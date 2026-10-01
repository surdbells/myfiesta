<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Events\OrderFullyRefunded;
use App\Events\OrderPaid;
use App\Events\TicketAdmitted;
use App\Listeners\RecordTicketMail;
use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\AbandonedCheckouts;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Door\CheckInService;
use App\Services\Refunds\RefundService;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * What the platform's own features hear: an order paid, an order fully
 * refunded, somebody let in.
 *
 * Each said exactly once, at the one place it happens, and only once what
 * happened has committed — a processor's notice delivered twice, a door's
 * scan sent again, a refund announced by both our request and the
 * processor, must each be heard once, and a transaction rolled back must be
 * heard not at all. Friend discounts and points are built on these.
 */
class DomainEventsTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_test_secret';

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        config([
            'payments.stripe.webhook_secret' => self::STRIPE_SECRET,
            'payments.stripe.secret_key' => 'sk_test_stripe',
        ]);
        $this->app->forgetInstance(PaymentGatewayRegistry::class);

        Mail::fake();
        Sleep::fake();

        Http::fake([
            'api.stripe.com/v1/refunds*' => fn (Request $request) => Http::response([
                'id' => 're_'.Str::random(8),
                'object' => 'refund',
                'amount' => (int) $request['amount'],
                'currency' => 'cad',
                'status' => 'succeeded',
            ]),
            'api.stripe.com/v1/checkout/sessions*' => fn (Request $request) => Http::response([
                'object' => 'list',
                'data' => [['id' => 'cs_'.Str::after((string) $request['payment_intent'], 'pi_'), 'object' => 'checkout.session']],
                'has_more' => false,
            ]),
            'api.stripe.com/*' => Http::response(['error' => ['message' => 'Not asked in this test.']], 404),
        ]);

        Events::fake([OrderPaid::class, OrderFullyRefunded::class, TicketAdmitted::class]);

        $organization = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $organization->id,
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

        $this->type = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 10000, 'status' => 'on_sale']);
    }

    private function checkout(int $quantity = 2): Order
    {
        $order = app(CheckoutService::class)->reserve($this->event, [$this->type->id => $quantity], 'ada@example.com', 'Ada Okafor');
        $order->update(['gateway' => 'stripe', 'gateway_reference' => 'cs_'.$order->reference]);

        return $order->refresh();
    }

    private function stripe(string $type, array $object, ?string $eventId = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.Str::random(12),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::STRIPE_SECRET);

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    private function pay(Order $order, ?string $eventId = null): TestResponse
    {
        return $this->stripe('checkout.session.completed', [
            'id' => $order->gateway_reference,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_'.$order->reference,
            'amount_total' => $order->total_amount,
            'currency' => 'cad',
        ], $eventId);
    }

    private function ticket(int $admits = 1): Ticket
    {
        return Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->type->id,
            'code' => Ticket::generateCode(),
            'owner_email' => 'guest@example.com',
            'holder_name' => 'Ada Okafor',
            'status' => 'valid',
            'admits' => $admits,
            'admitted_count' => 0,
        ]);
    }

    // --- paid ------------------------------------------------------------------

    public function test_a_paid_order_is_said_once_however_often_the_processor_says_so(): void
    {
        $order = $this->checkout();

        $this->pay($order, 'evt_once')->assertOk();
        $this->pay($order, 'evt_once')->assertOk();
        $this->pay($order, 'evt_again')->assertOk();

        Events::assertDispatchedTimes(OrderPaid::class, 1);
        Events::assertDispatched(OrderPaid::class, fn (OrderPaid $paid) => $paid->order->is($order)
            && $paid->order->status === 'paid'
            && $paid->order->tickets()->count() === 2);
    }

    public function test_money_that_arrives_after_the_checkout_closed_is_said_once_when_it_becomes_tickets(): void
    {
        $order = $this->checkout();
        $this->travel(3)->hours();
        app(AbandonedCheckouts::class)->expire();
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->pay($order)->assertOk();
        $this->pay($order)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        Events::assertDispatchedTimes(OrderPaid::class, 1);
    }

    public function test_a_payment_turned_away_is_not_a_sale(): void
    {
        $order = $this->checkout();
        $this->event->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'The venue flooded.']);

        $this->pay($order)->assertOk();

        $this->assertSame(0, $order->tickets()->count());
        Events::assertNotDispatched(OrderPaid::class);
    }

    public function test_a_payment_rolled_back_is_never_heard(): void
    {
        $order = $this->checkout();

        try {
            DB::transaction(function () use ($order) {
                app(Fulfiller::class)->fulfil($order);

                throw new RuntimeException('Something after it failed.');
            });
        } catch (RuntimeException) {
            // As a webhook that failed half way would.
        }

        $this->assertSame('pending', $order->fresh()->status);
        Events::assertNotDispatched(OrderPaid::class);
    }

    // --- refunded --------------------------------------------------------------

    public function test_the_refund_that_completes_an_order_is_said_once_and_a_partial_one_not_at_all(): void
    {
        $order = $this->checkout();
        $this->pay($order)->assertOk();
        [$first, $second] = $order->tickets()->orderBy('id')->pluck('id')->all();

        app(RefundService::class)->refund($order->fresh(), [$first], reason: 'Could not come.');

        $this->assertSame('partially_refunded', $order->fresh()->status);
        Events::assertNotDispatched(OrderFullyRefunded::class);

        app(RefundService::class)->refund($order->fresh(), [$second], reason: 'Neither could the other.');

        $this->assertSame('refunded', $order->fresh()->status);
        Events::assertDispatchedTimes(OrderFullyRefunded::class, 1);
        Events::assertDispatched(OrderFullyRefunded::class, fn (OrderFullyRefunded $refunded) => $refunded->order->is($order));
    }

    public function test_a_whole_refund_made_in_the_processors_dashboard_is_said_once_on_every_notice_of_it(): void
    {
        $order = $this->checkout();
        $this->pay($order)->assertOk();

        $refunded = [
            'id' => 'ch_'.$order->reference,
            'object' => 'charge',
            'amount' => $order->total_amount,
            'amount_captured' => $order->total_amount,
            'amount_refunded' => $order->total_amount,
            'currency' => 'cad',
            'payment_intent' => 'pi_'.$order->reference,
            'refunded' => true,
        ];

        $this->stripe('charge.refunded', $refunded, 'evt_refund')->assertOk();
        $this->stripe('charge.refunded', $refunded, 'evt_refund')->assertOk();
        $this->stripe('charge.refunded', $refunded, 'evt_refund_again')->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        Events::assertDispatchedTimes(OrderFullyRefunded::class, 1);
    }

    public function test_quiet_history_from_the_cutover_is_not_news(): void
    {
        $order = $this->checkout();
        $this->pay($order)->assertOk();

        app(RefundService::class)->recordMadeElsewhere($order->fresh(), $order->total_amount, 're_old', tellTheOrganizer: false);

        $this->assertSame('refunded', $order->fresh()->status);
        Events::assertNotDispatched(OrderFullyRefunded::class);
    }

    // --- admitted --------------------------------------------------------------

    public function test_an_admission_at_the_door_is_said_once_and_a_refusal_never(): void
    {
        $ticket = $this->ticket();
        $elsewhere = $this->event->replicate(['slug'])->fill(['slug' => 'another-night']);
        $elsewhere->save();
        $door = app(CheckInService::class);

        $this->assertTrue($door->scan($ticket->code, $this->event->id, clientId: $id = (string) Str::uuid())->admittedAnyone());
        // The same scan sent again, a second scan of a used ticket, a scan
        // of it at another night's door and a code nobody issued: nobody
        // else went in.
        $door->scan($ticket->code, $this->event->id, clientId: $id);
        $door->scan($ticket->code, $this->event->id);
        $door->scan($ticket->code, $elsewhere->id);
        $door->scan('NOT-ACODE', $this->event->id);

        Events::assertDispatchedTimes(TicketAdmitted::class, 1);
        Events::assertDispatched(TicketAdmitted::class, fn (TicketAdmitted $in) => $in->ticket->is($ticket)
            && $in->event->is($this->event)
            && $in->admitted === 1
            && $in->source === TicketAdmitted::ONLINE);
    }

    public function test_a_table_arriving_in_two_groups_is_said_twice_by_how_many_each_time_and_a_question_not_at_all(): void
    {
        $table = $this->ticket(admits: 5);
        $door = app(CheckInService::class);

        // No number on a ticket for five: the door is asked how many.
        $this->assertTrue($door->scan($table->code, $this->event->id)->asksHowMany());
        Events::assertNotDispatched(TicketAdmitted::class);

        $door->scan($table->code, $this->event->id, party: 3);
        $door->scan($table->code, $this->event->id, party: 2);

        $this->assertSame([3, 2], collect(Events::dispatched(TicketAdmitted::class))->map(fn (array $args) => $args[0]->admitted)->all());
    }

    public function test_an_admission_made_with_no_signal_is_said_once_when_it_syncs(): void
    {
        $ticket = $this->ticket();
        $refused = $this->ticket();
        $refused->update(['status' => 'void']);
        $door = app(CheckInService::class);
        $id = (string) Str::uuid();

        $door->recordOffline($ticket->code, $this->event->id, null, null, $id, 'accepted', now()->subMinutes(20));
        // The sync sent again after its answer was lost, and a guest the
        // door turned away with no signal.
        $door->recordOffline($ticket->code, $this->event->id, null, null, $id, 'accepted', now()->subMinutes(20));
        $door->recordOffline($refused->code, $this->event->id, null, null, (string) Str::uuid(), 'duplicate', now()->subMinutes(10));

        Events::assertDispatchedTimes(TicketAdmitted::class, 1);
        Events::assertDispatched(TicketAdmitted::class, fn (TicketAdmitted $in) => $in->ticket->is($ticket)
            && $in->source === TicketAdmitted::OFFLINE_SYNC);
    }

    // --- a feature that fails --------------------------------------------------

    /**
     * Listeners are queued, so one that fails fails alone and is tried again.
     * A listener run in place would stop every listener after it, and its
     * work would be lost: what it heard is never said a second time.
     */
    public function test_every_listener_on_these_is_queued(): void
    {
        $listeners = Events::getFacadeRoot()->dispatcher->getRawListeners();

        // What app/Listeners holds is what is read here, so this sees a
        // track's listener the day it lands.
        $this->assertContains(RecordTicketMail::class.'@handle', $listeners[MessageSent::class] ?? []);

        foreach ([OrderPaid::class, OrderFullyRefunded::class, TicketAdmitted::class] as $event) {
            foreach ($listeners[$event] ?? [] as $listener) {
                $class = is_string($listener) ? Str::before($listener, '@') : null;

                $this->assertTrue(
                    $class !== null && is_subclass_of($class, ShouldQueue::class),
                    sprintf('%s listens to %s without being queued (ShouldQueue).', is_string($listener) ? $listener : 'A closure', $event),
                );
            }
        }
    }

    public function test_a_feature_that_fails_on_a_paid_order_takes_nothing_from_the_buyer(): void
    {
        $this->aListenerThatFails(OrderPaid::class);
        $order = $this->checkout();

        $this->pay($order)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, $order->tickets()->count());
        Mail::assertQueued(TicketsIssued::class, 1);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'A feature with a bug.');
    }

    public function test_a_feature_that_fails_on_a_refund_leaves_the_refund_answered_and_audited(): void
    {
        $order = $this->checkout();
        $this->pay($order)->assertOk();
        $this->aListenerThatFails(OrderFullyRefunded::class);

        app(RefundService::class)->refund($order->fresh(), $order->tickets()->pluck('id')->all(), reason: 'Could not come.');

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'refund.processed')->exists());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_feature_that_fails_at_the_door_still_lets_the_guest_in(): void
    {
        $this->aListenerThatFails(TicketAdmitted::class);
        $ticket = $this->ticket();

        $this->assertTrue(app(CheckInService::class)->scan($ticket->code, $this->event->id)->admittedAnyone());

        $this->assertSame(1, $ticket->fresh()->admitted_count);
        Exceptions::assertReported(RuntimeException::class);
    }

    /** The real dispatcher back, and one feature on it with a bug. */
    private function aListenerThatFails(string $event): void
    {
        Exceptions::fake();

        $real = Events::getFacadeRoot()->dispatcher;
        Events::swap($real);
        Model::setEventDispatcher($real);

        $real->listen($event, fn () => throw new RuntimeException('A feature with a bug.'));
    }
}
