<?php

namespace Tests\Feature;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\Fulfiller;
use App\Services\Refunds\RefundRefused;
use App\Services\Refunds\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sending money back.
 *
 * The tests that matter here are the arithmetic ones. A refund that voids the
 * right tickets and returns the wrong amount looks correct from every screen
 * and is only found weeks later, in a settlement nobody can reconcile.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    private FakeRefundGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeRefundGateway;

        $registry = new PaymentGatewayRegistry;
        $registry->register($this->gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    /**
     * A paid order with $quantity tickets at 5000 each, plus 13% tax.
     */
    private function paidOrder(int $quantity = 2, int $discount = 0, int $serviceCharge = 0): Order
    {
        $subtotal = 5000 * $quantity;
        $tax = (int) round(($subtotal - $discount) * 0.13);
        // Tax is added on top here, so what the organizer earned is the
        // discounted price, and the service charge sits beside it rather than
        // coming out of it.
        $netRevenue = $subtotal - $discount;

        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'net_revenue_amount' => $netRevenue,
            'service_charge_amount' => $serviceCharge,
            'total_amount' => $netRevenue + $tax + $serviceCharge,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_test',
            'status' => 'pending',
        ]);

        $order->lines()->create([
            'ticket_type_id' => $this->type->id,
            'name' => 'General',
            'unit_price_amount' => 5000,
            'quantity' => $quantity,
            'line_total_amount' => $subtotal,
        ]);

        return app(Fulfiller::class)->fulfil($order->refresh());
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(User $user): void
    {
        Sanctum::actingAs($user, [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function refundRequest(Order $order, array $body = []): TestResponse
    {
        return $this->postJson(
            "/api/organizer/events/{$this->event->id}/orders/{$order->id}/refunds",
            $body,
        );
    }

    // --- the arithmetic ----------------------------------------------------

    public function test_refunding_everything_returns_exactly_what_was_paid(): void
    {
        $order = $this->paidOrder(quantity: 2);

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame('succeeded', $refund->status);
        $this->assertSame($order->total_amount, $refund->amount);
        $this->assertSame($order->tax_amount, $refund->tax_amount);
        $this->assertSame($order->total_amount, $this->gateway->lastAmount);
    }

    public function test_a_fully_refunded_order_nets_to_zero_in_the_ledger(): void
    {
        $order = $this->paidOrder(quantity: 2, serviceCharge: 700);

        app(RefundService::class)->refund($order);

        // The whole point of writing the reversal in the same shape as the
        // sale. If this is not zero, an organizer is owed something for an
        // event where nobody paid and nobody came.
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_refunding_half_the_tickets_returns_half_the_money(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $first = $order->tickets()->orderBy('created_at')->orderBy('id')->first();

        $refund = app(RefundService::class)->refund($order, [$first->id]);

        $this->assertSame(intdiv($order->total_amount, 2), $refund->amount);
        $this->assertSame('partially_refunded', $order->refresh()->status);
    }

    public function test_two_partial_refunds_add_up_to_the_whole(): void
    {
        // Three tickets and 13% tax gives a total that does not divide by
        // three, which is the case where per-ticket rounding loses a unit.
        $order = $this->paidOrder(quantity: 3);
        $tickets = $order->tickets()->orderBy('created_at')->orderBy('id')->get();

        $first = app(RefundService::class)->refund($order, [$tickets[0]->id]);
        $rest = app(RefundService::class)->refund($order, [$tickets[1]->id, $tickets[2]->id]);

        $this->assertSame($order->total_amount, $first->amount + $rest->amount);
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_tickets_of_different_prices_refund_their_own_share(): void
    {
        // Every other test here buys one price, which hides the whole point of
        // allocating by weight: a table refunds a table's worth and a single
        // ticket refunds a single ticket's worth.
        $table = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Table of 5',
            'price_amount' => 20000,
            'admits' => 5,
            'status' => 'on_sale',
        ]);

        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 25000,
            'tax_amount' => 3250,
            'net_revenue_amount' => 25000,
            'service_charge_amount' => 2000,
            'total_amount' => 30250,
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_test',
            'status' => 'pending',
        ]);

        $order->lines()->create([
            'ticket_type_id' => $this->type->id,
            'name' => 'General',
            'unit_price_amount' => 5000,
            'quantity' => 1,
            'line_total_amount' => 5000,
        ]);
        $order->lines()->create([
            'ticket_type_id' => $table->id,
            'name' => 'Table of 5',
            'unit_price_amount' => 20000,
            'quantity' => 1,
            'line_total_amount' => 20000,
        ]);

        $order = app(Fulfiller::class)->fulfil($order->refresh());

        $general = $order->tickets()->where('ticket_type_id', $this->type->id)->first();
        $seated = $order->tickets()->where('ticket_type_id', $table->id)->first();

        $small = app(RefundService::class)->refund($order, [$general->id]);
        $large = app(RefundService::class)->refund($order, [$seated->id]);

        // A fifth of the order and four fifths of it, and between them all of
        // it — not a unit less.
        $this->assertSame(6050, $small->amount);
        $this->assertSame(24200, $large->amount);
        $this->assertSame($order->total_amount, $small->amount + $large->amount);
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_a_discount_is_reflected_in_what_comes_back(): void
    {
        // Nobody gets back more than they paid, least of all the difference the
        // organizer chose to forgo.
        $order = $this->paidOrder(quantity: 2, discount: 2000);

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame($order->total_amount, $refund->amount);
        $this->assertLessThan(10000, $refund->amount);
    }

    public function test_commission_comes_back_to_the_organizer(): void
    {
        $order = $this->paidOrder(quantity: 2, serviceCharge: 700);

        app(RefundService::class)->refund($order);

        // Keeping a fee on a sale that was undone would make the organizer pay
        // for a refund out of their own pocket.
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)
            ->where('type', 'commission')->sum('amount'));
    }

    // --- what it does to tickets -------------------------------------------

    public function test_refunded_tickets_stop_opening_the_door(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $first = $order->tickets()->orderBy('created_at')->orderBy('id')->first();

        app(RefundService::class)->refund($order, [$first->id]);

        $this->assertSame('refunded', $first->refresh()->status);
        // The other one is untouched. A partial refund is not a cancellation.
        $this->assertSame('valid', $order->tickets()->orderBy('created_at')->orderBy('id')->get()[1]->status);
    }

    public function test_a_ticket_cannot_be_refunded_twice(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $first = $order->tickets()->orderBy('created_at')->orderBy('id')->first();

        app(RefundService::class)->refund($order, [$first->id]);

        $this->expectException(RefundRefused::class);
        app(RefundService::class)->refund($order, [$first->id]);
    }

    public function test_refunding_everything_twice_is_refused(): void
    {
        $order = $this->paidOrder(quantity: 2);
        app(RefundService::class)->refund($order);

        // Caught one step earlier than "no tickets left": the order's own
        // status already says it, and that is the more useful sentence.
        $this->expectExceptionMessage('Only a paid order can be refunded. This one is refunded.');
        app(RefundService::class)->refund($order->refresh());
    }

    public function test_a_ticket_from_another_order_cannot_be_refunded(): void
    {
        $mine = $this->paidOrder(quantity: 1);
        $theirs = $this->paidOrder(quantity: 1);
        $stranger = $theirs->tickets()->first();

        $this->expectException(RefundRefused::class);
        app(RefundService::class)->refund($mine, [$stranger->id]);
    }

    // --- when it goes wrong ------------------------------------------------

    public function test_a_refused_refund_leaves_the_tickets_valid(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $this->gateway->refuseWith('Your balance is too low.');

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame('failed', $refund->status);
        // The buyer still holds something they paid for.
        $this->assertSame(2, $order->tickets()->where('status', 'valid')->count());
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'refund')->count());
    }

    public function test_a_failed_refund_does_not_block_a_later_one(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $this->gateway->refuseWith('Temporarily unavailable.');
        app(RefundService::class)->refund($order);

        // A failed attempt reserved nothing, so the retry is not refused as
        // over-refunding.
        $this->gateway->succeed();
        $refund = app(RefundService::class)->refund($order);

        $this->assertSame('succeeded', $refund->status);
        $this->assertSame($order->total_amount, $refund->amount);
    }

    public function test_a_gateway_that_throws_is_recorded_as_a_failure(): void
    {
        $order = $this->paidOrder(quantity: 1);
        $this->gateway->throwOnRefund();

        $refund = app(RefundService::class)->refund($order);

        // If the exception escaped, the refund would stay pending forever and
        // hold part of the order's balance against every retry.
        $this->assertSame('failed', $refund->status);
        $this->assertNotNull($refund->failure_reason);
    }

    public function test_an_unpaid_order_cannot_be_refunded(): void
    {
        $order = $this->paidOrder(quantity: 1);
        $order->update(['status' => 'pending']);

        $this->expectExceptionMessage('Only a paid order can be refunded.');
        app(RefundService::class)->refund($order->refresh());
    }

    public function test_a_free_order_says_so_rather_than_calling_a_gateway(): void
    {
        $order = $this->paidOrder(quantity: 1);
        $order->update([
            'subtotal_amount' => 0, 'tax_amount' => 0,
            'net_revenue_amount' => 0, 'service_charge_amount' => 0, 'total_amount' => 0,
        ]);

        $this->expectExceptionMessage('This order was free, so there is nothing to return.');
        app(RefundService::class)->refund($order->refresh());
    }

    // --- the endpoint ------------------------------------------------------

    public function test_a_manager_can_refund_an_order(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $this->asOrganizer($this->member(Role::Manager));

        $this->refundRequest($order, ['reason' => 'Headliner cancelled'])
            ->assertCreated()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('amount.amount', $order->total_amount);
    }

    public function test_marketing_cannot_move_money(): void
    {
        $order = $this->paidOrder(quantity: 1);
        $this->asOrganizer($this->member(Role::Marketing));

        // Seeing what an event took is a long way from being able to move it.
        $this->refundRequest($order)->assertForbidden();
        $this->assertSame(0, Refund::count());
    }

    public function test_door_staff_cannot_refund(): void
    {
        $order = $this->paidOrder(quantity: 1);
        $this->asOrganizer($this->member(Role::Door));

        $this->refundRequest($order)->assertForbidden();
    }

    public function test_another_organizations_order_is_not_reachable(): void
    {
        $order = $this->paidOrder(quantity: 1);

        $otherOrg = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $stranger = User::factory()->create();
        $otherOrg->members()->attach($stranger->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        $this->asOrganizer($stranger->fresh()->load('organizations'));

        $this->refundRequest($order)->assertForbidden();
        $this->assertSame(0, Refund::count());
    }

    public function test_the_endpoint_never_accepts_an_amount(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $this->asOrganizer($this->member(Role::Manager));

        // The same rule that stops a buyer choosing what to pay, applied at
        // the other end. Sending an amount changes nothing.
        $this->refundRequest($order, ['amount' => 1])
            ->assertCreated()
            ->assertJsonPath('amount.amount', $order->total_amount);
    }

    public function test_a_gateway_failure_is_not_repeated_as_a_validation_error(): void
    {
        $order = $this->paidOrder(quantity: 1);
        $this->gateway->refuseWith('card_declined: insufficient_funds');
        $this->asOrganizer($this->member(Role::Manager));

        // 502, because nothing about the request was wrong.
        $response = $this->refundRequest($order)->assertStatus(502);

        // The processor's own words stay out of it. "card_declined" is about
        // the card, not about anything an organizer can do, and the console
        // would show it verbatim.
        $response->assertJsonPath('display', true);
        $this->assertStringNotContainsString('card_declined', $response->json('message'));
        $this->assertStringContainsString('No money has moved', $response->json('message'));

        // Still recorded in full where support can find it.
        $this->assertStringContainsString(
            'card_declined',
            Refund::first()->failure_reason,
        );
    }

    public function test_the_order_list_shows_what_is_left_to_refund(): void
    {
        $order = $this->paidOrder(quantity: 2);
        $first = $order->tickets()->orderBy('created_at')->orderBy('id')->first();
        app(RefundService::class)->refund($order, [$first->id]);

        $this->asOrganizer($this->member(Role::Manager));

        $this->getJson("/api/organizer/events/{$this->event->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'partially_refunded')
            ->assertJsonPath('data.0.refunded.amount', intdiv($order->total_amount, 2))
            ->assertJsonCount(2, 'data.0.tickets');
    }

    public function test_the_order_list_never_carries_ticket_codes(): void
    {
        $order = $this->paidOrder(quantity: 1);
        $code = $order->tickets()->first()->code;

        $this->asOrganizer($this->member(Role::Manager));

        // Read on a laptop in an office; a ticket code opens a door.
        $this->getJson("/api/organizer/events/{$this->event->id}/orders")
            ->assertOk()
            ->assertDontSee($code);
    }
}

/**
 * A processor that does what the test tells it to.
 *
 * Refund tests must never reach Stripe, and mocking the HTTP client would test
 * the shape of a request rather than what the service does with the answer.
 */
class FakeRefundGateway implements PaymentGateway
{
    public ?int $lastAmount = null;

    private bool $succeeds = true;

    private bool $throws = false;

    private string $reason = '';

    public function refuseWith(string $reason): void
    {
        $this->succeeds = false;
        $this->reason = $reason;
    }

    public function succeed(): void
    {
        $this->succeeds = true;
        $this->throws = false;
    }

    public function throwOnRefund(): void
    {
        $this->throws = true;
    }

    // Named after a real gateway because orders_gateway_check only accepts
    // the ones that exist. The registry is replaced wholesale, so nothing
    // reaches Stripe.
    public function name(): string
    {
        return 'stripe';
    }

    public function supports(string $currency): bool
    {
        return true;
    }

    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession
    {
        throw new \LogicException('Not needed for refund tests.');
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        return null;
    }

    public function refund(Order $order, int $amountMinorUnits, ?string $reason = null): RefundResult
    {
        $this->lastAmount = $amountMinorUnits;

        if ($this->throws) {
            throw new \RuntimeException('connection reset');
        }

        return $this->succeeds
            ? new RefundResult(true, 're_test', $amountMinorUnits, $order->currency)
            : RefundResult::failed($this->reason);
    }
}
