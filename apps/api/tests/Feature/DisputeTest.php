<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Disputes\RiskSignals;
use App\Services\Refunds\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A bank taking the money back.
 *
 * Three things are being protected. That a dispute arriving does not void
 * somebody's ticket — a claim is not a verdict, and plenty are dropped. That
 * losing one does: the money is gone, and a working ticket on a charge that
 * was taken back is ticket fraud with the organizer paying for the drinks.
 * And that neither is ever counted as a refund, which is the organizer's own
 * decision and the one thing this must not be confused with.
 */
class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private Organization $org;

    private Event $event;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payments.stripe.webhook_secret', self::SECRET);
        Config::set('payments.stripe.secret_key', 'sk_test');

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);

        $this->order = $this->paidOrder();
    }

    private function paidOrder(string $email = 'ada@example.com', int $tickets = 2): Order
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => $email,
            'buyer_name' => 'Ada Okafor',
            'currency' => 'CAD',
            'subtotal_amount' => 5000 * $tickets,
            'total_amount' => 5000 * $tickets,
            'net_revenue_amount' => 5000 * $tickets,
            'gateway' => 'stripe',
            'gateway_reference' => 'cs_'.Str::random(10),
            'gateway_payment_reference' => 'pi_'.Str::random(10),
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // What a refund splits the money by.
        $order->lines()->create([
            'ticket_type_id' => $this->event->ticketTypes()->value('id'),
            'name' => 'General',
            'unit_price_amount' => 5000,
            'quantity' => $tickets,
            'line_total_amount' => 5000 * $tickets,
        ]);

        for ($i = 0; $i < $tickets; $i++) {
            Ticket::create([
                'event_id' => $this->event->id,
                'ticket_type_id' => $this->event->ticketTypes()->value('id'),
                'order_id' => $order->id,
                'owner_email' => $email,
                'code' => strtoupper(Str::random(12)),
                'status' => 'valid',
            ]);
        }

        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'order_id' => $order->id,
            'type' => 'sale',
            'amount' => 5000 * $tickets,
            'currency' => 'CAD',
            'occurred_at' => now(),
            'reason' => "Order {$order->reference}",
        ]);

        return $order;
    }

    /** A signed Stripe delivery, as Stripe would send it. */
    private function stripe(string $type, array $object, ?string $id = null): TestResponse
    {
        $payload = json_encode([
            'id' => $id ?? 'evt_'.Str::random(10),
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();

        return $this->call(
            'POST',
            '/webhooks/payments/stripe',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET),
            ],
            content: $payload,
        );
    }

    /** Stripe saying yes to a refund, for the amount it was asked for. */
    private function stripeAcceptsRefunds(): void
    {
        Http::fake([
            'api.stripe.com/v1/refunds*' => fn (Request $request) => Http::response([
                'id' => 're_'.Str::random(10),
                'amount' => (int) $request['amount'],
                'currency' => 'cad',
                'status' => 'succeeded',
            ]),
        ]);
    }

    private function disputeObject(array $overrides = []): array
    {
        return [
            'id' => 'dp_test123',
            'payment_intent' => $this->order->gateway_payment_reference,
            'amount' => 10000,
            'currency' => 'cad',
            'reason' => 'product_not_received',
            'evidence_details' => ['due_by' => now()->addDays(7)->getTimestamp()],
            ...$overrides,
        ];
    }

    // --- opening ---------------------------------------------------------------

    public function test_a_dispute_is_recorded_against_the_order_it_names(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();

        $dispute = Dispute::sole();
        $this->assertSame($this->order->id, $dispute->order_id);
        $this->assertSame('open', $dispute->status);
        $this->assertSame(10000, (int) $dispute->amount);
        $this->assertSame('product_not_received', $dispute->reason);
        $this->assertNotNull($dispute->evidence_due_at);
        $this->assertNotNull($this->order->fresh()->disputed_at);
    }

    public function test_a_claim_is_not_a_verdict(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();

        // Nothing is taken back and nobody is turned away at the door on the
        // strength of a dispute that has not been decided.
        $this->assertSame(2, Ticket::where('status', 'valid')->count());
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame('paid', $this->order->fresh()->status);
    }

    public function test_the_same_dispute_delivered_twice_is_one_dispute(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject(), 'evt_one')->assertOk();
        $this->stripe('charge.dispute.created', $this->disputeObject(), 'evt_two')->assertOk();

        $this->assertSame(1, Dispute::count());
    }

    // --- losing ------------------------------------------------------------------

    public function test_losing_takes_the_money_back_and_stops_the_tickets(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'lost']))->assertOk();

        $this->assertSame('lost', Dispute::sole()->status);

        $chargeback = LedgerEntry::where('type', 'chargeback')->sole();
        $this->assertSame(-10000, (int) $chargeback->amount);
        $this->assertStringContainsString($this->order->reference, $chargeback->reason);

        // The ledger nets to nothing: the sale and the chargeback cancel.
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $this->order->id)->sum('amount'));

        $this->assertSame(2, Ticket::where('status', 'void')->count());

        // Not a refund. An organizer's own refunds are a different decision
        // and are counted separately everywhere.
        $this->assertSame('paid', $this->order->fresh()->status);
        $this->assertSame(0, $this->order->refunds()->count());
    }

    public function test_a_lost_dispute_delivered_twice_takes_the_money_once(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'lost']), 'evt_close_one')->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'lost']), 'evt_close_two')->assertOk();

        $this->assertSame(1, LedgerEntry::where('type', 'chargeback')->count());
    }

    public function test_losing_after_a_partial_refund_takes_back_only_what_is_left(): void
    {
        $this->stripeAcceptsRefunds();

        // One of the two tickets refunded by the organizer, then the whole
        // payment disputed and lost.
        app(RefundService::class)->refund($this->order, [$this->order->tickets()->orderBy('id')->value('id')]);

        $this->assertSame(5000, (int) LedgerEntry::where('order_id', $this->order->id)->sum('amount'));

        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'lost']))->assertOk();

        // The half they still had, not the whole order a second time.
        $this->assertSame(-5000, (int) LedgerEntry::where('type', 'chargeback')->sole()->amount);
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $this->order->id)->sum('amount'));

        // The ticket still working stops; the refunded one stays refunded.
        $this->assertSame(['refunded', 'void'], Ticket::where('order_id', $this->order->id)->orderBy('status')->pluck('status')->all());
    }

    public function test_losing_a_fully_refunded_order_takes_back_nothing(): void
    {
        $this->stripeAcceptsRefunds();

        app(RefundService::class)->refund($this->order);

        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'lost']))->assertOk();

        $this->assertSame('lost', Dispute::sole()->status);

        // The organizer gave it all back already. Charging them again would
        // take money they do not have.
        $this->assertSame(0, LedgerEntry::where('type', 'chargeback')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $this->order->id)->sum('amount'));
    }

    public function test_losing_an_order_that_was_never_a_sale_takes_back_nothing(): void
    {
        // A late payment for places that had gone: refunded whole, with no
        // tickets and no sale ever written for the organizer.
        $late = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'late@example.com',
            'buyer_name' => 'Late Buyer',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'total_amount' => 5000,
            'net_revenue_amount' => 5000,
            'gateway' => 'stripe',
            'gateway_reference' => 'cs_'.Str::random(10),
            'gateway_payment_reference' => 'pi_late',
            'status' => 'refunded',
            'paid_at' => now(),
            'refunded_at' => now(),
        ]);

        $this->stripe('charge.dispute.created', $this->disputeObject(['id' => 'dp_late', 'payment_intent' => 'pi_late', 'amount' => 5000]))->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['id' => 'dp_late', 'payment_intent' => 'pi_late', 'amount' => 5000, 'status' => 'lost']))->assertOk();

        $this->assertSame('lost', Dispute::where('order_id', $late->id)->sole()->status);
        $this->assertSame(0, LedgerEntry::where('order_id', $late->id)->count());

        // And nobody else's balance pays for it either.
        $this->assertSame(10000, (int) LedgerEntry::where('organization_id', $this->org->id)->sum('amount'));
    }

    public function test_winning_leaves_everything_where_it_was(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'won']))->assertOk();

        $this->assertSame('won', Dispute::sole()->status);
        $this->assertSame(0, LedgerEntry::where('type', 'chargeback')->count());
        $this->assertSame(2, Ticket::where('status', 'valid')->count());
    }

    public function test_a_network_dropping_an_enquiry_counts_as_winning(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'warning_closed']))->assertOk();

        $this->assertSame('won', Dispute::sole()->status);
    }

    // --- finding the order ----------------------------------------------------------

    public function test_the_payment_reference_is_kept_when_the_payment_settles(): void
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'new@example.com',
            'buyer_name' => 'New Buyer',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'total_amount' => 5000,
            'net_revenue_amount' => 5000,
            'gateway' => 'stripe',
            'gateway_reference' => 'cs_new123',
            'status' => 'pending',
        ]);

        $order->lines()->create([
            'ticket_type_id' => $this->event->ticketTypes()->value('id'),
            'name' => 'General',
            'unit_price_amount' => 5000,
            'quantity' => 1,
            'line_total_amount' => 5000,
        ]);

        $this->stripe('checkout.session.completed', [
            'id' => 'cs_new123',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_new123',
            'amount_total' => 5000,
            'currency' => 'cad',
        ])->assertOk();

        // Without this, a dispute names a payment nothing here has heard of.
        $this->assertSame('pi_new123', $order->fresh()->gateway_payment_reference);

        $this->stripe('charge.dispute.created', [
            'id' => 'dp_new',
            'payment_intent' => 'pi_new123',
            'amount' => 5000,
            'currency' => 'cad',
            'reason' => 'fraudulent',
        ])->assertOk();

        $this->assertSame($order->id, Dispute::where('gateway_reference', 'dp_new')->sole()->order_id);
    }

    // --- what an organizer sees ------------------------------------------------------

    public function test_an_organizer_sees_the_facts_about_a_buyer_not_a_score(): void
    {
        $this->stripe('charge.dispute.created', $this->disputeObject())->assertOk();
        $this->stripe('charge.dispute.closed', $this->disputeObject(['status' => 'lost']))->assertOk();

        // The same address buys again.
        $again = $this->paidOrder('ada@example.com', 1);
        // And somebody with nothing behind them.
        $clean = $this->paidOrder('chidi@example.com', 1);

        $owner = User::factory()->create();
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);
        Sanctum::actingAs($owner->fresh(), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);

        $rows = collect($this->getJson('/api/organizer/orders')->assertOk()->json('data'))->keyBy('id');

        $this->assertContains('previous_chargeback', $rows[$again->id]['signals']);
        $this->assertContains('disputed', $rows[$this->order->id]['signals']);
        $this->assertSame([], $rows[$clean->id]['signals']);
    }

    public function test_a_burst_of_orders_from_one_address_is_mentioned(): void
    {
        $orders = collect(range(1, RiskSignals::BURST + 1))->map(fn () => $this->paidOrder('busy@example.com', 1));

        $signals = app(RiskSignals::class)->forOrders($orders);

        $this->assertContains('many_orders', $signals[$orders->first()->id]);
        // The one address that has only bought once is not mentioned.
        $this->assertArrayNotHasKey($this->order->id, $signals);
    }
}
