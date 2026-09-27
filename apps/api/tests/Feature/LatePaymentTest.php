<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Mail\SoldOutWhilePaying;
use App\Mail\TicketsIssued;
use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\AbandonedCheckouts;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A payment that reaches us after its hold has run out.
 *
 * It happens every on-sale: a Paystack transfer confirming an hour later, a
 * Stripe webhook retried after an outage, a buyer who sat on the payment page.
 * Refusing it would keep somebody's money for nothing; honouring it blindly
 * sold places that had already gone to somebody who paid on time. What it gets
 * is the stock counted again — tickets if there is room, and every penny back
 * if there is not.
 *
 * Driven through the signed webhook, with the processor faked at the HTTP
 * edge, so the refund here is the one Stripe would actually be asked for.
 */
class LatePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_test_secret';

    private Event $event;

    /** Whether Stripe turns refunds down, as it does with no balance to pay them from. */
    private bool $stripeRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        config([
            'payments.stripe.webhook_secret' => self::STRIPE_SECRET,
            'payments.stripe.secret_key' => 'sk_test_stripe',
            'payments.paystack.secret_key' => 'sk_test_paystack',
        ]);

        $this->app->forgetInstance(PaymentGatewayRegistry::class);

        Mail::fake();

        // Stripe accepts a refund for the amount it was asked for, unless a
        // test says otherwise. Asked what it has refunded — which it is, after
        // saying no — it has refunded nothing.
        Http::fake([
            'api.stripe.com/v1/refunds*' => fn (Request $request) => match (true) {
                $request->method() === 'GET' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false]),
                $this->stripeRefuses => Http::response(['error' => ['message' => 'Insufficient balance.']], 402),
                default => Http::response([
                    'id' => 're_'.uniqid(),
                    'amount' => (int) $request['amount'],
                    'currency' => 'cad',
                ]),
            },
        ]);

        $this->event = Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function tier(?int $capacity, string $name = 'General'): TicketType
    {
        return TicketType::create([
            'event_id' => $this->event->id,
            'name' => $name,
            'price_amount' => 10000,
            'status' => 'on_sale',
            'quantity_available' => $capacity,
        ]);
    }

    /** A checkout that has opened its Stripe page. */
    private function checkout(array $tickets, string $email, array $addOns = []): Order
    {
        $order = app(CheckoutService::class)->reserve(
            $this->event, $tickets, $email, 'Ada Okafor', addOns: $addOns,
        );

        $order->update(['gateway' => 'stripe', 'gateway_reference' => 'cs_'.uniqid()]);

        return $order->refresh();
    }

    /** Stripe saying the page was paid. */
    private function pay(Order $order, ?string $eventId = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.$order->reference,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $order->gateway_reference,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_'.$order->reference,
                'amount_total' => $order->total_amount,
                'currency' => 'cad',
            ]],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::STRIPE_SECRET);

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    /** Past the hold, and past the sweep that closes abandoned checkouts. */
    private function walkAway(): void
    {
        $this->travel(CheckoutService::HOLD_MINUTES + 1)->minutes();
    }

    private function refundsAskedFor(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/refunds'))->count();
    }

    // --- room left --------------------------------------------------------

    public function test_a_late_payment_is_honoured_while_there_is_room(): void
    {
        $type = $this->tier(5);

        $late = $this->checkout([$type->id => 2], 'late@example.com');
        $this->travel(3)->hours();
        app(AbandonedCheckouts::class)->expire();

        $this->assertSame('cancelled', $late->fresh()->status);

        $this->pay($late)->assertOk();

        $this->assertSame('paid', $late->fresh()->status);
        $this->assertSame(2, $late->tickets()->count());
        $this->assertSame(0, $this->refundsAskedFor());
        $this->assertSame(0, Refund::count());

        // Exactly as if it had been on time, ledger included.
        $this->assertSame(
            $late->net_revenue_amount,
            (int) LedgerEntry::where('order_id', $late->id)->sum('amount'),
        );
        Mail::assertQueued(TicketsIssued::class);
        Mail::assertNotQueued(SoldOutWhilePaying::class);
    }

    public function test_a_late_payment_fits_around_other_peoples_live_holds(): void
    {
        $type = $this->tier(3);

        $late = $this->checkout([$type->id => 1], 'late@example.com');
        $this->walkAway();

        // Two places are in somebody else's basket right now. The third is
        // still free, and the late payment can have it.
        $this->checkout([$type->id => 2], 'paying@example.com');

        $this->pay($late)->assertOk();

        $this->assertSame('paid', $late->fresh()->status);
        $this->assertSame(0, $type->fresh()->remainingNow());
    }

    // --- no room left -----------------------------------------------------

    public function test_a_late_payment_for_places_since_sold_is_refunded_in_full(): void
    {
        $type = $this->tier(2);

        $late = $this->checkout([$type->id => 2], 'late@example.com');
        $this->walkAway();

        // Somebody else buys the same two places, on time.
        $prompt = $this->checkout([$type->id => 2], 'prompt@example.com');
        $this->pay($prompt)->assertOk();

        // And then the first payment lands.
        $this->pay($late)->assertOk();

        $late->refresh();

        // Nothing issued, so nothing oversold.
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame(2, Ticket::where('ticket_type_id', $type->id)->count(), 'Two places, two tickets.');
        $this->assertSame(2, $prompt->tickets()->count());

        // Every penny back, service charge included: they were sold nothing.
        $this->assertSame('refunded', $late->status);
        $this->assertNotNull($late->refunded_at);

        $refund = $late->refunds()->sole();
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame($late->total_amount, (int) $refund->amount);
        $this->assertSame($late->service_charge_amount, (int) $refund->service_charge_amount);
        $this->assertSame(0, $refund->tickets()->count());

        // Asked of Stripe against the payment, not the checkout page.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/refunds')
            && $request['payment_intent'] === 'pi_'.$late->reference
            && (int) $request['amount'] === $late->total_amount);

        // The organizer never made this sale, so their balance never sees it.
        $this->assertSame(0, LedgerEntry::where('order_id', $late->id)->count());
        $this->assertSame(
            $prompt->net_revenue_amount,
            (int) LedgerEntry::where('event_id', $this->event->id)->sum('amount'),
        );

        // The processor kept its fee for taking the money; that is the
        // platform's cost, recorded where the platform's side lives.
        $this->assertNotNull($late->paid_at);
        $this->assertGreaterThan(0, $late->gateway_fee_amount);

        $this->assertTrue(AuditLog::where('action', 'order.sold_out_while_paying')->where('subject_id', $late->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'refund.processed')->where('subject_id', $late->id)->exists());

        Mail::assertQueued(SoldOutWhilePaying::class, fn (SoldOutWhilePaying $mail) => $mail->hasTo('late@example.com')
            && $mail->order->is($late)
            && (int) $mail->refund->amount === $late->total_amount);
        Mail::assertQueued(TicketsIssued::class, 1);
    }

    public function test_the_buyer_is_told_plainly(): void
    {
        $type = $this->tier(1);

        $late = $this->checkout([$type->id => 1], 'late@example.com');
        $this->walkAway();
        $this->pay($this->checkout([$type->id => 1], 'prompt@example.com'));
        $this->pay($late);

        $mail = new SoldOutWhilePaying($late->fresh(), $late->refunds()->sole());
        $html = $mail->render();

        $this->assertStringContainsString('sold out while you were paying', $html);
        $this->assertStringContainsString('every', $html);
        $this->assertStringContainsString('CA$'.number_format($late->total_amount / 100, 2), $html);
        $this->assertStringContainsString($late->reference, $html);
    }

    public function test_part_of_an_order_fitting_is_not_enough(): void
    {
        $general = $this->tier(10);
        $table = AddOn::create([
            'event_id' => $this->event->id,
            'name' => 'VIP table',
            'price_amount' => 50000,
            'status' => 'on_sale',
            'quantity_available' => 1,
        ]);

        $late = $this->checkout([$general->id => 2], 'late@example.com', [$table->id => 1]);
        $this->walkAway();

        // The only table goes to somebody else. General is nowhere near full.
        $this->pay($this->checkout([$general->id => 1], 'prompt@example.com', [$table->id => 1]))->assertOk();

        $this->pay($late)->assertOk();

        // Two tickets without the table is not what they paid for.
        $this->assertSame('refunded', $late->fresh()->status);
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame(0, $table->fresh()->remainingNow());
    }

    public function test_a_late_payment_for_a_tier_taken_off_sale_is_refunded_not_stuck(): void
    {
        $type = $this->tier(50);

        $late = $this->checkout([$type->id => 2], 'late@example.com');
        $this->walkAway();

        // Nobody has bought any, and nobody holds any now, so the organizer
        // can remove it — and does.
        $type->delete();

        // It used to be looked up in a way that threw: the notice failed, and
        // so did every retry, with the money kept and nothing issued or
        // returned. A place taken off sale has gone like one sold.
        $this->pay($late)->assertOk();

        $late->refresh();
        $this->assertSame('refunded', $late->status);
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame($late->total_amount, (int) $late->refunds()->sole()->amount);
        $this->assertSame(0, LedgerEntry::where('order_id', $late->id)->count());

        Mail::assertQueued(SoldOutWhilePaying::class, fn (SoldOutWhilePaying $mail) => $mail->hasTo('late@example.com'));
    }

    public function test_the_same_late_payment_delivered_twice_is_refunded_once(): void
    {
        $type = $this->tier(1);

        $late = $this->checkout([$type->id => 1], 'late@example.com');
        $this->walkAway();
        $this->pay($this->checkout([$type->id => 1], 'prompt@example.com'));

        // A retried delivery, and then the same payment again under a new
        // event id — which the webhook's own memory cannot catch.
        $this->pay($late)->assertOk();
        $this->pay($late)->assertOk();
        $this->pay($late, eventId: 'evt_other')->assertOk();
        app(Fulfiller::class)->fulfil($late->fresh());

        $this->assertSame(1, $this->refundsAskedFor());
        $this->assertSame(1, $late->refunds()->count());
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame('refunded', $late->fresh()->status);
        Mail::assertQueued(SoldOutWhilePaying::class, 1);
    }

    public function test_a_refund_stripe_refuses_is_left_for_a_person_not_retold_as_done(): void
    {
        $this->stripeRefuses = true;

        $type = $this->tier(1);

        $late = $this->checkout([$type->id => 1], 'late@example.com');
        $this->walkAway();
        $this->pay($this->checkout([$type->id => 1], 'prompt@example.com'));
        $this->pay($late)->assertOk();

        $late->refresh();

        // Still nothing issued. The money is still owed, and the order shows
        // that it came and has not gone back.
        $this->assertSame(0, $late->tickets()->count());
        $this->assertNotSame('refunded', $late->status);
        $this->assertNotNull($late->paid_at);
        $this->assertSame('failed', $late->refunds()->sole()->status);

        $this->assertTrue(AuditLog::where('action', 'refund.failed')->where('subject_id', $late->id)->exists());

        // "On its way back" would not be true.
        Mail::assertNotQueued(SoldOutWhilePaying::class);
    }

    public function test_a_paystack_transfer_that_confirms_too_late_goes_back_through_paystack(): void
    {
        // Lagos, where this happens most: a bank transfer that confirms after
        // the night has sold out.
        $this->event->update(['currency' => 'NGN', 'country' => 'NG', 'subdivision' => null, 'timezone' => 'Africa/Lagos']);
        $type = $this->tier(1);

        Http::fake([
            'api.paystack.co/refund' => fn (Request $request) => Http::response([
                'status' => true,
                'data' => ['id' => 4242, 'amount' => (int) $request['amount'], 'currency' => 'NGN'],
            ]),
        ]);

        $late = app(CheckoutService::class)->reserve($this->event, [$type->id => 1], 'late@example.com', 'Chidi');
        $late->update(['gateway' => 'paystack', 'gateway_reference' => 'PSK-'.$late->reference]);
        $this->walkAway();

        $prompt = app(CheckoutService::class)->reserve($this->event, [$type->id => 1], 'prompt@example.com', 'Ngozi');
        app(Fulfiller::class)->fulfil($prompt->refresh());

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 777,
                'reference' => $late->gateway_reference,
                'amount' => $late->fresh()->total_amount,
                'currency' => 'NGN',
            ],
        ]);

        $this->call('POST', '/webhooks/payments/paystack',
            server: ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'sk_test_paystack'), 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        )->assertOk();

        $late->refresh();

        $this->assertSame('refunded', $late->status);
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame(1, Ticket::where('ticket_type_id', $type->id)->count());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/refund'
            && $request['transaction'] === $late->gateway_reference
            && (int) $request['amount'] === $late->total_amount);

        Mail::assertQueued(SoldOutWhilePaying::class, fn (SoldOutWhilePaying $mail) => $mail->hasTo('late@example.com'));
    }

    // --- orders that are not waiting for a payment --------------------------

    public function test_a_payment_for_a_refunded_order_issues_nothing(): void
    {
        $type = $this->tier(null);

        $order = $this->checkout([$type->id => 1], 'ada@example.com');
        $order->update(['status' => 'refunded']);

        $this->pay($order)->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame(0, $this->refundsAskedFor());
    }

    public function test_a_payment_for_a_failed_order_issues_nothing(): void
    {
        $type = $this->tier(null);

        $order = $this->checkout([$type->id => 1], 'ada@example.com');
        $order->update(['status' => 'failed']);

        $this->pay($order)->assertOk();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
    }

    public function test_a_part_refunded_order_is_not_issued_again(): void
    {
        $type = $this->tier(null);

        $order = $this->checkout([$type->id => 2], 'ada@example.com');
        $this->pay($order)->assertOk();

        // Part refunded by the organizer, then the original payment notice
        // arrives again under a different id.
        $order->update(['status' => 'partially_refunded']);
        $this->pay($order, eventId: 'evt_again')->assertOk();

        $this->assertSame(2, $order->tickets()->count(), 'No second set of tickets.');
        $this->assertSame('partially_refunded', $order->fresh()->status);
    }

    // --- the processor's side of it ------------------------------------------

    public function test_stripe_is_given_the_reason_where_it_accepts_one(): void
    {
        $type = $this->tier(1);

        $late = $this->checkout([$type->id => 1], 'late@example.com');
        $this->walkAway();
        $this->pay($this->checkout([$type->id => 1], 'prompt@example.com'));
        $this->pay($late);

        // Stripe's own `reason` takes one of three fixed words and refuses the
        // refund over anything else. A sentence goes in metadata.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/refunds')
            && ! isset($request->data()['reason'])
            && ($request->data()['metadata']['reason'] ?? null) === 'Sold out while the buyer was paying.');
    }
}
