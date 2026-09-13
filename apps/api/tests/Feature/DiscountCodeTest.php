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
use App\Exceptions\CheckoutException;
use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Refunds\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Discount codes doing what the organizer set them up to do.
 *
 * Every limit on this screen used to be decorative in one way or another: the
 * per-buyer limit was stored and never read, and the total limit counted
 * abandoned checkouts, so a code ran out long before as many people had paid.
 */
class DiscountCodeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    private TicketType $vip;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = new PaymentGatewayRegistry;
        $registry->register(new DiscountTestGateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        // No tax rate seeded: the arithmetic below is about the discount.
        $this->event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'currency' => 'CAD',
        ]);

        $this->general = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale', 'sort_order' => 1]);
        $this->vip = TicketType::create(['event_id' => $this->event->id, 'name' => 'VIP', 'price_amount' => 15000, 'status' => 'on_sale', 'sort_order' => 2]);
    }

    private function code(array $attributes = []): Code
    {
        return Code::create(array_merge([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'SAVE',
            'discount_type' => 'percentage',
            'discount_value' => 2000,
            'is_active' => true,
        ], $attributes));
    }

    private function checkout(array $quantities, string $email = 'ada@example.com', ?string $code = 'SAVE'): Order
    {
        return app(CheckoutService::class)->reserve($this->event, $quantities, $email, 'Ada Okafor', $code);
    }

    private function pay(Order $order): Order
    {
        $order->update(['gateway' => 'stripe']);

        return app(Fulfiller::class)->fulfil($order->refresh());
    }

    // --- limits ---------------------------------------------------------------

    public function test_an_abandoned_checkout_gives_its_use_back(): void
    {
        $code = $this->code(['max_redemptions' => 1]);

        $this->checkout([$this->general->id => 1], 'first@example.com');

        // While the first checkout is live, the last use is taken.
        try {
            $this->checkout([$this->general->id => 1], 'second@example.com');
            $this->fail('Two checkouts shared the last use.');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('fully redeemed', $e->getMessage());
        }

        // The first buyer walks away. Once the hold has lapsed the use is free
        // again — it used to be spent for good.
        $this->travel(21)->minutes();

        $this->checkout([$this->general->id => 1], 'second@example.com');

        $this->assertSame(0, $code->fresh()->redemption_count, 'Nothing has been paid for yet.');
    }

    public function test_the_count_is_paid_uses_and_a_full_refund_returns_one(): void
    {
        $code = $this->code();

        $order = $this->pay($this->checkout([$this->general->id => 1]));
        $this->checkout([$this->general->id => 1], 'abandoned@example.com');

        $this->assertSame(1, $code->fresh()->redemption_count);

        app(RefundService::class)->refund($order->refresh());

        $this->assertSame(0, $code->fresh()->redemption_count);
    }

    public function test_the_per_buyer_limit_is_enforced(): void
    {
        $this->code(['max_per_customer' => 1]);

        $this->pay($this->checkout([$this->general->id => 1], 'ada@example.com'));

        try {
            // Same person, different capitalisation.
            $this->checkout([$this->general->id => 1], 'ADA@example.com');
            $this->fail('The per-buyer limit was ignored.');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('once per person', $e->getMessage());
        }

        $this->checkout([$this->general->id => 1], 'chidi@example.com');
    }

    public function test_a_payment_after_its_hold_lapsed_is_fulfilled_even_past_the_cap(): void
    {
        $code = $this->code(['max_redemptions' => 1]);

        $late = $this->checkout([$this->general->id => 1], 'late@example.com');
        $this->travel(21)->minutes();
        $this->pay($this->checkout([$this->general->id => 1], 'prompt@example.com'));

        // The first buyer's payment lands late. They paid; they get tickets.
        $this->pay($late->refresh());

        $this->assertSame('paid', $late->fresh()->status);
        $this->assertSame(2, $code->fresh()->redemption_count);
    }

    // --- finding the code -----------------------------------------------------

    public function test_this_events_code_is_found_when_another_event_has_one_of_the_same_name(): void
    {
        $other = Event::factory()->published()->create(['organization_id' => $this->org->id]);
        $this->code(['event_id' => $other->id, 'discount_value' => 5000]);
        $this->code(['discount_value' => 2000]);

        $quote = app(CheckoutService::class)->quote($this->event, [$this->general->id => 1], 'save');

        $this->assertSame(1000, $quote->discount->amount);
    }

    // --- aiming ---------------------------------------------------------------

    public function test_a_code_for_general_leaves_vip_full_price(): void
    {
        $this->code()->ticketTypes()->sync([$this->general->id]);

        $quote = app(CheckoutService::class)->quote($this->event, [$this->general->id => 2, $this->vip->id => 1], 'SAVE');

        $this->assertSame(2000, $quote->discount->amount, '20% of the two General tickets only.');

        $lines = collect($quote->lines)->keyBy(fn ($l) => $l->ticketType->name);
        $this->assertSame(2000, $lines['General']->discount->amount);
        $this->assertSame(0, $lines['VIP']->discount->amount);
    }

    public function test_a_typed_code_that_covers_nothing_in_the_basket_says_what_it_covers(): void
    {
        $this->code()->ticketTypes()->sync([$this->general->id]);

        $this->expectExceptionMessage('SAVE only applies to General');

        app(CheckoutService::class)->quote($this->event, [$this->vip->id => 1], 'SAVE');
    }

    public function test_a_group_code_needs_enough_tickets(): void
    {
        $this->code(['min_quantity' => 4]);

        try {
            app(CheckoutService::class)->quote($this->event, [$this->general->id => 3], 'SAVE');
            $this->fail('A four-ticket deal applied to three.');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('at least 4', $e->getMessage());
        }

        $this->assertSame(4000, app(CheckoutService::class)->quote($this->event, [$this->general->id => 4], 'SAVE')->discount->amount);
    }

    public function test_a_promoter_link_still_credits_the_sale_when_its_discount_does_not_apply(): void
    {
        $this->code(['ref_slug' => 'tobi', 'min_quantity' => 4]);

        $quote = app(CheckoutService::class)->quote($this->event, [$this->general->id => 1], null, 'tobi');

        $this->assertSame(0, $quote->discount->amount);
        $this->assertSame('tobi', $quote->refSlug);
        $this->assertNotNull($quote->code);
    }

    public function test_refunding_the_undiscounted_ticket_returns_what_it_cost(): void
    {
        $this->code()->ticketTypes()->sync([$this->general->id]);

        $order = $this->pay($this->checkout([$this->general->id => 1, $this->vip->id => 1]));
        $vipTicket = $order->tickets()->where('ticket_type_id', $this->vip->id)->sole();

        $refund = app(RefundService::class)->refund($order->refresh(), [$vipTicket->id]);

        // VIP was 150.00 with none of the General discount on it. Weighting by
        // list price alone would have taken part of that discount off.
        $expected = 15000 + (int) round(15000 * config('payments.service_charge_bps', 800) / 10000);
        $this->assertSame($expected, $refund->amount);
    }

    // --- the organizer's side -------------------------------------------------

    private function asOwner(): void
    {
        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh()->load('organizations'), [TokenAbility::Organizer->value]);
    }

    public function test_a_code_is_created_aimed_at_tickets_and_reports_what_it_sold(): void
    {
        $this->asOwner();

        $id = $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'group4',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'min_quantity' => 4,
            'ticket_type_ids' => [$this->general->id],
        ])->assertCreated()
            ->assertJsonPath('min_quantity', 4)
            ->assertJsonPath('ticket_types.0.name', 'General')
            ->json('id');

        $this->pay(app(CheckoutService::class)->reserve($this->event, [$this->general->id => 4], 'ada@example.com', 'Ada', 'GROUP4'));

        $this->getJson("/api/organizer/events/{$this->event->id}/codes")
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.redemption_count', 1)
            ->assertJsonPath('data.0.sales.0.orders', 1)
            ->assertJsonPath('data.0.sales.0.tickets', 4)
            ->assertJsonPath('data.0.sales.0.revenue', 18000)
            ->assertJsonPath('data.0.sales.0.discount', 2000);
    }

    public function test_a_name_differing_only_in_case_is_refused_cleanly(): void
    {
        $this->asOwner();
        $this->code();

        // Used to pass validation and fail on the database index as a 500.
        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'save', 'discount_type' => 'percentage', 'discount_value' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_two_codes_cannot_track_the_same_link(): void
    {
        $this->asOwner();
        $this->code(['ref_slug' => 'tobi']);

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'TOBI2', 'ref_slug' => 'TOBI',
        ])->assertStatus(422)->assertJsonValidationErrors('ref_slug');
    }

    public function test_the_limit_cannot_go_below_what_has_been_used(): void
    {
        $this->asOwner();
        $code = $this->code();
        $this->pay($this->checkout([$this->general->id => 1], 'a@example.com'));
        $this->pay($this->checkout([$this->general->id => 1], 'b@example.com'));

        // Used to hit a database constraint and return a 500.
        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$code->id}", ['max_redemptions' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'already been used 2 times'));
    }

    public function test_an_all_events_code_cannot_be_aimed_at_one_events_tickets(): void
    {
        $this->asOwner();

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'EVERYWHERE', 'discount_type' => 'percentage', 'discount_value' => 1000,
            'event_scoped' => false, 'ticket_type_ids' => [$this->general->id],
        ])->assertStatus(422);
    }

    public function test_a_ticket_from_another_event_cannot_be_named(): void
    {
        $this->asOwner();
        $elsewhere = TicketType::create(['event_id' => Event::factory()->create(['organization_id' => $this->org->id])->id, 'name' => 'Other', 'price_amount' => 100, 'status' => 'on_sale']);

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", [
            'code' => 'STRAY', 'discount_type' => 'percentage', 'discount_value' => 1000,
            'ticket_type_ids' => [$elsewhere->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ticket_type_ids.0');
    }
}

/** Refunds succeed; nothing else is used. */
class DiscountTestGateway implements PaymentGateway
{
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
        throw new \LogicException('Not used.');
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
        return new RefundResult(true, 're_test', $amountMinorUnits, $order->currency);
    }
}
