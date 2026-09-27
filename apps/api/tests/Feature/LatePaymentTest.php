<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Enums\PlatformRole;
use App\Exceptions\CheckoutException;
use App\Mail\SoldOutWhilePaying;
use App\Mail\TicketsIssued;
use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\AbandonedCheckouts;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Checkout\TurnedAway;
use App\Services\Door\DoorPasses;
use App\Services\Organizations\Suspension;
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

    // --- a night that is not happening ---------------------------------------

    public function test_a_late_payment_for_an_event_cancelled_since_is_refunded_not_issued(): void
    {
        $type = $this->tier(50);

        $late = $this->checkout([$type->id => 2], 'late@example.com');
        $this->walkAway();

        // Called off while the buyer's bank was still deciding. There is
        // plenty of room — nobody else can buy either — and room is not the
        // question any more.
        $this->event->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'The venue flooded.']);

        $this->pay($late)->assertOk();

        $late->refresh();

        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame('refunded', $late->status);

        // Every penny back, the same way a sold-out late payment goes back.
        $refund = $late->refunds()->sole();
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame($late->total_amount, (int) $refund->amount);
        $this->assertSame($late->service_charge_amount, (int) $refund->service_charge_amount);
        $this->assertSame(TurnedAway::Cancelled->refundReason(), $refund->reason);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/refunds')
            && ($request->data()['metadata']['reason'] ?? null) === 'The event was cancelled before the payment arrived.');

        // Never a sale, so never on the organizer's balance.
        $this->assertSame(0, LedgerEntry::where('order_id', $late->id)->count());

        $this->assertTrue(AuditLog::where('action', 'order.event_cancelled_while_paying')->where('subject_id', $late->id)->exists());
        $this->assertFalse(AuditLog::where('action', 'order.sold_out_while_paying')->exists());

        // Told it was cancelled, not that it sold out.
        Mail::assertNotQueued(TicketsIssued::class);
        Mail::assertQueued(SoldOutWhilePaying::class, fn (SoldOutWhilePaying $mail) => $mail->hasTo('late@example.com')
            && $mail->why() === TurnedAway::Cancelled);
    }

    public function test_a_payment_still_holding_its_places_is_refunded_when_the_event_was_called_off(): void
    {
        $type = $this->tier(50);

        // On the payment page, hold live, when the organizer cancelled. The
        // cancellation refunded every paid order; this one was not paid yet.
        $order = $this->checkout([$type->id => 1], 'onthepage@example.com');
        $this->event->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->pay($order)->assertOk();

        $order->refresh();
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame('refunded', $order->status);
        $this->assertSame(0, $order->holds()->count(), 'The hold goes; there is nothing left to hold it for.');
        $this->assertSame(1, $this->refundsAskedFor());
    }

    public function test_a_payment_for_an_event_staff_took_down_is_refunded(): void
    {
        $type = $this->tier(50);

        $late = $this->checkout([$type->id => 1], 'late@example.com');
        $this->walkAway();

        $this->event->update([
            'status' => 'draft',
            'taken_down_at' => now(),
            'taken_down_reason' => 'Reported as a copy of another promoter\'s event.',
        ]);

        $this->pay($late)->assertOk();

        $late->refresh();
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame('refunded', $late->status);
        $this->assertSame(TurnedAway::TakenDown->refundReason(), $late->refunds()->sole()->reason);
        $this->assertTrue(AuditLog::where('action', 'order.event_taken_down_while_paying')->where('subject_id', $late->id)->exists());

        Mail::assertQueued(SoldOutWhilePaying::class, fn (SoldOutWhilePaying $mail) => $mail->why() === TurnedAway::TakenDown);
    }

    public function test_a_payment_that_arrives_after_the_event_ended_is_refunded(): void
    {
        $this->event->update(['ends_at' => $this->event->starts_at->copy()->addHours(5)]);
        $type = $this->tier(50);

        $late = $this->checkout([$type->id => 1], 'late@example.com');

        // The transfer confirms the morning after.
        $this->travelTo($this->event->starts_at->copy()->addHours(9));

        $this->pay($late)->assertOk();

        $late->refresh();
        $this->assertSame(0, $late->tickets()->count());
        $this->assertSame('refunded', $late->status);
        $this->assertSame(TurnedAway::Ended->refundReason(), $late->refunds()->sole()->reason);
        $this->assertTrue(AuditLog::where('action', 'order.event_ended_while_paying')->where('subject_id', $late->id)->exists());

        Mail::assertQueued(SoldOutWhilePaying::class, fn (SoldOutWhilePaying $mail) => $mail->why() === TurnedAway::Ended);
    }

    public function test_a_payment_during_the_night_is_still_honoured(): void
    {
        $type = $this->tier(50);

        $late = $this->checkout([$type->id => 1], 'late@example.com');

        // Started two hours ago and nothing says it has finished: an event
        // with no end time runs half a day, as far as the door is concerned.
        $this->travelTo($this->event->starts_at->copy()->addHours(2));

        $this->pay($late)->assertOk();

        $this->assertSame('paid', $late->fresh()->status);
        $this->assertSame(1, $late->tickets()->count());
        $this->assertSame(0, $this->refundsAskedFor());
    }

    public function test_an_event_with_no_end_time_is_over_once_its_door_closes_after_half_a_day(): void
    {
        $type = $this->tier(50);

        $late = $this->checkout([$type->id => 1], 'late@example.com');

        // Half a day in, the listing is over but the door is not: a ticket
        // bought now still gets somebody in.
        $this->travelTo($this->event->starts_at->copy()->addHours(TurnedAway::HOURS_WITHOUT_AN_END)->addMinute());
        $this->assertNull(TurnedAway::forEvent($this->event->fresh(), now()));

        $this->travelTo($this->event->starts_at->copy()->addHours(TurnedAway::HOURS_WITHOUT_AN_END + DoorPasses::GRACE_HOURS)->addMinute());

        $this->pay($late)->assertOk();

        $this->assertSame('refunded', $late->fresh()->status);
        $this->assertSame(0, $late->tickets()->count());
    }

    public function test_a_payment_on_its_way_at_the_listed_end_is_honoured_while_the_door_is_open(): void
    {
        $this->event->update(['ends_at' => $this->event->starts_at->copy()->addHours(5)]);
        $type = $this->tier(50);

        // Started paying five minutes before the end, as it says on the page.
        $this->travelTo($this->event->ends_at->copy()->subMinutes(5));
        $order = $this->checkout([$type->id => 1], 'lastminute@example.com');

        // And the payment lands twenty minutes after it. The door is still
        // open, so this is a ticket that gets somebody in, not one to refund.
        $this->travelTo($this->event->ends_at->copy()->addMinutes(20));

        $this->pay($order)->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(1, $order->tickets()->count());
        $this->assertSame(0, $this->refundsAskedFor());
        $this->assertSame($order->net_revenue_amount, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        Mail::assertQueued(TicketsIssued::class);
        Mail::assertNotQueued(SoldOutWhilePaying::class);
    }

    public function test_checkout_stops_selling_online_at_the_listed_end(): void
    {
        $this->event->update(['ends_at' => $this->event->starts_at->copy()->addHours(5)]);
        $type = $this->tier(50);
        $free = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'Guest list',
            'price_amount' => 0,
            'status' => 'on_sale',
            'quantity_available' => 50,
        ]);

        // An hour after the end, still published, and no sales end on the
        // tier. It used to take the money and then send it straight back,
        // keeping nothing but the processor's fee — which was the platform's.
        $this->travelTo($this->event->ends_at->copy()->addHour());

        foreach ([$type, $free] as $tier) {
            try {
                app(CheckoutService::class)->reserve($this->event->fresh(), [$tier->id => 1], 'late@example.com', 'Ada Okafor');
                $this->fail("A ticket for a night that is over was reserved ({$tier->name}).");
            } catch (CheckoutException $e) {
                $this->assertSame('This event has ended, so tickets for it are no longer on sale.', $e->getMessage());
            }
        }

        // Said at the quote, before anybody types a card number.
        $this->postJson("/api/events/{$this->event->slug}/quote", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', 'This event has ended, so tickets for it are no longer on sale.');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, InventoryHold::count());
        $this->assertSame(0, $this->refundsAskedFor());
    }

    public function test_a_door_sale_after_the_listed_end_is_still_a_sale(): void
    {
        // The party ran late. The door is still open, the cash is in the
        // tin, and there is no processor to send it back through.
        $this->event->update([
            'starts_at' => now()->subHours(6),
            'ends_at' => now()->subHour(),
        ]);
        $type = $this->tier(50);

        $order = app(CheckoutService::class)->reserve(
            $this->event, [$type->id => 1], null, 'Door sale',
            channel: 'door', paymentMethod: 'cash', soldBy: User::factory()->create(),
        );

        app(Fulfiller::class)->fulfil($order);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->tickets()->count());
        $this->assertSame(0, Refund::count());
    }

    public function test_a_payment_for_an_organizer_suspended_meanwhile_is_refunded(): void
    {
        $type = $this->tier(50);

        // On the payment page, places held, when staff suspended the
        // organizer. Checkout would refuse this buyer now; their payment was
        // already on its way.
        $order = $this->checkout([$type->id => 2], 'onthepage@example.com');

        app(Suspension::class)->suspend(
            Organization::findOrFail($this->event->organization_id),
            User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]),
            'Chargebacks on three events in a week.',
        );

        $this->assertSame('draft', $this->event->fresh()->status);

        $this->pay($order)->assertOk();

        $order->refresh();

        // No tickets for a sale the platform stopped, and nothing on the
        // balance of an organization whose money is frozen.
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame('refunded', $order->status);
        $this->assertSame(0, $order->holds()->count());
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->count());

        $refund = $order->refunds()->sole();
        $this->assertSame($order->total_amount, (int) $refund->amount);
        $this->assertSame(TurnedAway::Suspended->refundReason(), $refund->reason);
        $this->assertTrue(AuditLog::where('action', 'order.organizer_suspended_while_paying')->where('subject_id', $order->id)->exists());

        Mail::assertNotQueued(TicketsIssued::class);
        Mail::assertQueued(SoldOutWhilePaying::class, fn (SoldOutWhilePaying $mail) => $mail->hasTo('onthepage@example.com')
            && $mail->why() === TurnedAway::Suspended);
    }

    public function test_a_payment_after_a_suspension_was_lifted_is_honoured(): void
    {
        $type = $this->tier(50);
        $organization = Organization::findOrFail($this->event->organization_id);
        $admin = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);

        $order = $this->checkout([$type->id => 1], 'patient@example.com');

        app(Suspension::class)->suspend($organization, $admin, 'Chargebacks on three events in a week.');
        app(Suspension::class)->unsuspend($organization, $admin, 'Looked into; nothing wrong.');

        $this->pay($order)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->tickets()->count());
        $this->assertSame(0, $this->refundsAskedFor());
    }

    public function test_each_reason_is_told_in_its_own_words(): void
    {
        $type = $this->tier(1);

        $late = $this->checkout([$type->id => 1], 'late@example.com');
        $this->walkAway();
        $this->pay($this->checkout([$type->id => 1], 'prompt@example.com'));
        $this->pay($late);

        $refund = $late->refunds()->sole();

        $said = fn (TurnedAway $why) => (new SoldOutWhilePaying($late->fresh(), tap($refund->replicate(), fn (Refund $r) => $r->reason = $why->refundReason())))->render();

        $this->assertStringContainsString('Afro Fest has been cancelled', $said(TurnedAway::Cancelled));
        $this->assertStringContainsString('after the event had ended', $said(TurnedAway::Ended));
        $this->assertStringContainsString('Afro Fest is no longer on sale', $said(TurnedAway::TakenDown));

        // A suspension is between the platform and the organizer. The buyer
        // hears what checkout would have told them, and nothing about why.
        $this->assertStringContainsString('Afro Fest is no longer on sale', $said(TurnedAway::Suspended));
        $this->assertStringContainsString('no longer selling tickets on myFiesta', $said(TurnedAway::Suspended));
        $this->assertStringNotContainsStringIgnoringCase('suspend', $said(TurnedAway::Suspended));

        foreach ([TurnedAway::Cancelled, TurnedAway::Suspended, TurnedAway::Ended, TurnedAway::TakenDown] as $why) {
            $html = $said($why);

            $this->assertStringNotContainsString('sold out', $html);
            $this->assertStringContainsString('CA$'.number_format($late->total_amount / 100, 2), $html);
            $this->assertStringContainsString($late->reference, $html);
        }

        // A refund written before there was more than one reason said sold
        // out, and still does.
        $this->assertSame(TurnedAway::SoldOut, TurnedAway::fromRefundReason(null));
        $this->assertStringContainsString('sold out while you were paying', $said(TurnedAway::SoldOut));
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
