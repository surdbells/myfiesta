<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Refunds\RefundRefused;
use App\Services\Refunds\RefundService;
use Closure;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One refund is one refund at the processor, however many times it is sent.
 *
 * A refund request that times out may have paid the money back a moment before
 * the connection dropped. It used to be written down as failed, which told the
 * organizer to send it again, and the second request was a second refund. Here
 * the same request is sent to Stripe as it really goes out — through the
 * gateway, faked only at the HTTP edge — and the fake behaves as Stripe and
 * Paystack do: Stripe answers a repeated key with the refund it already made,
 * and Paystack, which takes no key, makes another.
 */
class RefundIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $type;

    /** Refunds the fake Stripe has made, by the idempotency key that made them. */
    private array $stripeByKey = [];

    /** Every refund request Stripe was sent, with the key it carried. */
    private array $stripePosts = [];

    /** How many of the next refund requests never reach an answer. */
    private int $stripeUnreachable = 0;

    /** An answer Stripe gives every refund request instead of making one, as [status, message]. */
    private ?array $stripeSays = null;

    /** What Stripe says of each refund it makes. */
    private string $stripeRefundStatus = 'succeeded';

    /** Something that happens after Stripe makes a refund and before its answer arrives. */
    private ?Closure $whileStripeAnswers = null;

    /** The refunds Paystack has made, as its list returns them. */
    private array $paystackRefunds = [];

    private int $paystackPosts = 0;

    private int $paystackUnreachable = 0;

    /** Whether Paystack's refund list is down. */
    private bool $paystackListDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        config([
            'payments.stripe.webhook_secret' => 'whsec_test_secret',
            'payments.stripe.secret_key' => 'sk_test_stripe',
            'payments.paystack.secret_key' => 'sk_test_paystack',
        ]);

        $this->app->forgetInstance(PaymentGatewayRegistry::class);

        Mail::fake();
        // The gateway waits a moment before trying again; the test need not.
        Sleep::fake();

        Http::fake([
            'api.stripe.com/v1/refunds*' => fn (Request $request) => $request->method() === 'POST'
                ? $this->stripeRefund($request)
                : $this->stripeList($request),
            'api.paystack.co/refund*' => fn (Request $request) => match (true) {
                $request->method() === 'POST' => $this->paystackRefund($request),
                $this->paystackListDown => Http::response(['status' => false, 'message' => 'Service unavailable'], 503),
                default => Http::response(['status' => true, 'data' => $this->paystackRefunds]),
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

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 10000,
            'status' => 'on_sale',
        ]);
    }

    // --- the fake processors ------------------------------------------------------

    /** Stripe: a repeated key gets the first answer back, never a second refund. */
    private function stripeRefund(Request $request)
    {
        $key = $request->header('Idempotency-Key')[0] ?? null;
        $this->stripePosts[] = ['key' => $key, 'data' => $request->data()];

        if ($this->stripeUnreachable > 0) {
            $this->stripeUnreachable--;

            return (Http::failedConnection())($request);
        }

        if ($this->stripeSays !== null) {
            return Http::response(['error' => ['message' => $this->stripeSays[1]]], $this->stripeSays[0]);
        }

        $refund = $key !== null && isset($this->stripeByKey[$key])
            ? $this->stripeByKey[$key]
            : [
                'id' => 're_'.count($this->stripeByKey).uniqid(),
                'object' => 'refund',
                'amount' => (int) $request['amount'],
                'currency' => 'cad',
                'payment_intent' => $request['payment_intent'],
                'metadata' => $request->data()['metadata'] ?? [],
                'status' => $this->stripeRefundStatus,
            ];

        $this->stripeByKey[$key ?? uniqid()] = $refund;

        if ($this->whileStripeAnswers !== null) {
            [$then, $this->whileStripeAnswers] = [$this->whileStripeAnswers, null];
            $then($refund);
        }

        return Http::response($refund);
    }

    /** A signed Stripe notice. */
    private function stripeNotice(string $type, array $object): TestResponse
    {
        $payload = json_encode([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_secret');

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    /** Somebody refunding the whole order in Stripe's dashboard, with no tag of ours. */
    private function refundInTheDashboard(Order $order): void
    {
        $this->stripeByKey['dashboard'] = [
            'id' => 're_dashboard',
            'object' => 'refund',
            'amount' => $order->total_amount,
            'currency' => 'cad',
            'payment_intent' => 'pi_'.$order->reference,
            'metadata' => [],
            'status' => 'succeeded',
        ];
    }

    private function stripeList(Request $request)
    {
        return Http::response([
            'object' => 'list',
            'data' => array_values(array_filter(
                $this->stripeByKey,
                fn (array $refund) => $refund['payment_intent'] === $request['payment_intent'],
            )),
            'has_more' => false,
        ]);
    }

    /** Paystack: no key, so every request that arrives is a refund. */
    private function paystackRefund(Request $request)
    {
        $this->paystackPosts++;

        $refund = [
            'id' => 9000 + count($this->paystackRefunds),
            'transaction' => 5550001,
            'amount' => (int) $request['amount'],
            'currency' => 'NGN',
            'status' => 'pending',
            'merchant_note' => $request['merchant_note'],
            'createdAt' => now()->toIso8601String(),
        ];

        $this->paystackRefunds[] = $refund;

        // The request reached Paystack and the refund was made; the answer
        // never came back.
        if ($this->paystackUnreachable > 0) {
            $this->paystackUnreachable--;

            return (Http::failedConnection())($request);
        }

        return Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => $refund]);
    }

    // --- orders ------------------------------------------------------------------

    private function stripeOrder(int $quantity = 2): Order
    {
        $order = app(CheckoutService::class)->reserve($this->event, [$this->type->id => $quantity], 'ada@example.com', 'Ada Okafor');

        $order->update([
            'gateway' => 'stripe',
            'gateway_reference' => 'cs_'.$order->reference,
            'gateway_payment_reference' => 'pi_'.$order->reference,
        ]);

        return app(Fulfiller::class)->fulfil($order->refresh());
    }

    private function paystackOrder(int $quantity = 2): Order
    {
        $this->event->update(['currency' => 'NGN', 'country' => 'NG', 'subdivision' => null, 'timezone' => 'Africa/Lagos']);

        $order = app(CheckoutService::class)->reserve($this->event, [$this->type->id => $quantity], 'chidi@example.com', 'Chidi Obi');

        $order->update([
            'gateway' => 'paystack',
            'gateway_reference' => 'PSK-'.$order->reference,
            // The transaction's own id, which Paystack's refund list names it by.
            'gateway_payment_reference' => '5550001',
        ]);

        return app(Fulfiller::class)->fulfil($order->refresh());
    }

    private function laterOn(): void
    {
        $this->travel(RefundService::FOLLOW_UP_AFTER_MINUTES + 1)->minutes();
    }

    // --- Stripe ------------------------------------------------------------------

    public function test_stripe_is_sent_each_refund_under_its_own_key_and_the_reason_as_a_note(): void
    {
        $order = $this->stripeOrder();

        $refund = app(RefundService::class)->refund($order, reason: 'Could not make it — family emergency');

        $this->assertSame('succeeded', $refund->status);

        $this->assertCount(1, $this->stripePosts);
        $this->assertSame('refund-'.$refund->id, $this->stripePosts[0]['key']);
        $this->assertSame($refund->id, $this->stripePosts[0]['data']['metadata']['refund_id']);

        // Stripe's `reason` takes one of three words and refuses the whole
        // refund over anything else. A sentence somebody typed goes where
        // Stripe keeps it without checking it.
        $this->assertArrayNotHasKey('reason', $this->stripePosts[0]['data']);
        $this->assertSame('Could not make it — family emergency', $this->stripePosts[0]['data']['metadata']['reason']);
    }

    public function test_a_stripe_timeout_is_tried_again_under_the_same_key(): void
    {
        $order = $this->stripeOrder();
        $this->stripeUnreachable = 1;

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame('succeeded', $refund->status);
        $this->assertCount(2, $this->stripePosts);
        $this->assertSame($this->stripePosts[0]['key'], $this->stripePosts[1]['key']);
        $this->assertCount(1, $this->stripeByKey, 'One refund at Stripe.');
        $this->assertSame('refunded', $order->refresh()->status);
    }

    public function test_a_stripe_refund_nobody_answered_waits_instead_of_failing(): void
    {
        $order = $this->stripeOrder();
        $this->stripeUnreachable = 10;

        $refund = app(RefundService::class)->refund($order);

        // Three tries under one key, and still nothing heard.
        $this->assertCount(3, $this->stripePosts);
        $this->assertCount(1, array_unique(array_column($this->stripePosts, 'key')));

        $this->assertSame('pending', $refund->status);
        $this->assertNotNull($refund->unanswered_at);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'valid')->count(), 'Tickets keep working until somebody knows.');
        $this->assertSame(0, LedgerEntry::where('order_id', $order->id)->where('type', 'refund')->count());
        $this->assertTrue(AuditLog::where('action', 'refund.unanswered')->where('subject_id', $order->id)->exists());

        // Not sendable again under a new row while it waits.
        $this->expectException(RefundRefused::class);
        app(RefundService::class)->refund($order);
    }

    public function test_the_follow_up_finds_a_refund_stripe_made_and_sends_nothing(): void
    {
        $order = $this->stripeOrder();

        // Stripe made the refund; the answer was lost on the way back.
        $this->stripeUnreachable = 3;
        $refund = app(RefundService::class)->refund($order);
        $this->stripeByKey['refund-'.$refund->id] = [
            'id' => 're_made_anyway',
            'object' => 'refund',
            'amount' => (int) $refund->amount,
            'currency' => 'cad',
            'payment_intent' => 'pi_'.$order->reference,
            'metadata' => ['refund_id' => $refund->id],
            'status' => 'succeeded',
        ];

        // Not before it has waited.
        $this->artisan('refunds:follow-up')->assertSuccessful();
        $this->assertSame('pending', $refund->fresh()->status);

        $this->laterOn();
        $this->artisan('refunds:follow-up')->assertSuccessful();

        $refund->refresh();
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('re_made_anyway', $refund->gateway_reference);
        $this->assertCount(3, $this->stripePosts, 'Asked about, not sent again.');

        // And now it is a refund like any other.
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        $this->assertTrue(AuditLog::where('action', 'refund.processed')->where('subject_id', $order->id)->exists());

        // A second run finds nothing waiting.
        $this->artisan('refunds:follow-up')->expectsOutput('No refund is waiting for an answer.');
    }

    public function test_the_follow_up_sends_it_again_under_the_same_key_when_stripe_has_none(): void
    {
        $order = $this->stripeOrder();

        $this->stripeUnreachable = 3;
        $refund = app(RefundService::class)->refund($order);

        $this->laterOn();
        $this->artisan('refunds:follow-up')->assertSuccessful();

        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertCount(4, $this->stripePosts);
        $this->assertCount(1, array_unique(array_column($this->stripePosts, 'key')));
        $this->assertCount(1, $this->stripeByKey);
    }

    public function test_a_stripe_refusal_is_a_no_and_can_be_asked_again(): void
    {
        $this->stripeSays = [402, 'Insufficient balance.'];

        $order = $this->stripeOrder();

        $refund = app(RefundService::class)->refund($order);

        // A refusal moved nothing, and is safe to try again — once, not three
        // times behind the organizer's back.
        $this->assertSame('failed', $refund->status);
        $this->assertNull($refund->unanswered_at);
        $this->assertCount(1, $this->stripePosts);
        $this->assertSame(2, $order->tickets()->where('status', 'valid')->count());
    }

    public function test_a_refund_stripe_accepts_as_already_failed_is_not_counted_as_paid(): void
    {
        $this->stripeRefundStatus = 'failed';

        $order = $this->stripeOrder();

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame('failed', $refund->status);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'valid')->count());
    }

    public function test_stripes_own_error_is_not_read_as_a_no(): void
    {
        $this->stripeSays = [500, 'An unknown error occurred'];

        $order = $this->stripeOrder();

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame('pending', $refund->status);
        $this->assertNotNull($refund->unanswered_at);
        $this->assertCount(3, $this->stripePosts, 'Tried again under the same key, which is safe with Stripe.');
    }

    public function test_a_refund_its_own_notice_settles_mid_request_is_written_down_once(): void
    {
        $order = $this->stripeOrder();

        // The first request times out. Before the retry comes back, Stripe's
        // notice about the refund it made arrives and settles it.
        $this->stripeUnreachable = 1;
        $this->whileStripeAnswers = fn (array $refund) => $this->stripeNotice('refund.created', $refund + ['charge' => 'ch_'.$order->reference])
            ->assertOk();

        $refund = app(RefundService::class)->refund($order);

        $this->assertSame('succeeded', $refund->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));

        // One refund, one line in a log nobody may correct.
        $this->assertSame(1, AuditLog::where('subject_id', $order->id)->where('action', 'like', 'refund.%')->count());
    }

    public function test_a_dashboard_refund_hidden_behind_ours_is_found_when_ours_is_refused(): void
    {
        $order = $this->stripeOrder();

        // Ours never reaches Stripe, and waits.
        $this->stripeUnreachable = 3;
        $ours = app(RefundService::class)->refund($order);
        $this->assertSame('pending', $ours->status);

        // Support refunds the buyer in the dashboard meanwhile. The running
        // total is read against ours, which is on record and unfinished, so
        // it finds nothing new.
        $this->refundInTheDashboard($order);
        $this->stripeNotice('charge.refunded', [
            'id' => 'ch_'.$order->reference,
            'object' => 'charge',
            'amount' => $order->total_amount,
            'amount_refunded' => $order->total_amount,
            'currency' => 'cad',
            'payment_intent' => 'pi_'.$order->reference,
        ])->assertOk();

        $this->assertSame(1, Refund::count());

        // The follow-up finds no refund of ours at Stripe and sends it — and
        // Stripe says no, the money has already gone back.
        $this->stripeSays = [400, 'Charge ch_'.$order->reference.' has already been refunded.'];
        $this->laterOn();
        $this->artisan('refunds:follow-up')->assertSuccessful();

        $this->assertSame('failed', $ours->fresh()->status);

        // Nothing would announce that total again. Asked for, it is found: the
        // buyer has everything back, so the tickets stop and the organizer's
        // balance stops counting the sale.
        $dashboard = Refund::where('source', Refund::FROM_PROCESSOR)->sole();
        $this->assertSame('succeeded', $dashboard->status);
        $this->assertSame($order->total_amount, (int) $dashboard->amount);
        $this->assertSame('re_dashboard', $dashboard->gateway_reference);

        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'refunded')->count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        $this->assertTrue(AuditLog::where('action', 'refund.made_elsewhere')->where('subject_id', $order->id)->exists());
    }

    public function test_a_refund_refused_because_the_money_already_went_back_finds_where_it_went(): void
    {
        $order = $this->stripeOrder();

        // Refunded in the dashboard, and the notice about it lost.
        $this->refundInTheDashboard($order);
        $this->stripeSays = [400, 'Charge ch_'.$order->reference.' has already been refunded.'];

        $ours = app(RefundService::class)->refund($order);

        $this->assertSame('failed', $ours->status);
        $this->assertSame($order->total_amount, (int) Refund::where('source', Refund::FROM_PROCESSOR)->sole()->amount);
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    // --- Paystack ----------------------------------------------------------------

    public function test_paystack_is_never_sent_a_refund_twice_on_a_timeout(): void
    {
        $order = $this->paystackOrder();
        $this->paystackUnreachable = 1;

        $refund = app(RefundService::class)->refund($order);

        // Paystack takes no idempotency key, so a second request would be a
        // second refund. One request, and the refund waits.
        $this->assertSame(1, $this->paystackPosts);
        $this->assertSame('pending', $refund->status);
        $this->assertNotNull($refund->unanswered_at);
        $this->assertStringContainsString('[myFiesta refund '.$refund->id.']', $this->paystackRefunds[0]['merchant_note']);
    }

    public function test_the_follow_up_asks_paystack_before_sending_anything_again(): void
    {
        $order = $this->paystackOrder();
        $this->paystackUnreachable = 1;

        $refund = app(RefundService::class)->refund($order, reason: 'Could not come');

        $this->laterOn();
        $this->artisan('refunds:follow-up')->assertSuccessful();

        $refund->refresh();
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame((string) $this->paystackRefunds[0]['id'], $refund->gateway_reference);
        $this->assertSame(1, $this->paystackPosts, 'Found by the note it was sent with, and not sent again.');
        $this->assertCount(1, $this->paystackRefunds);
        $this->assertSame('refunded', $order->refresh()->status);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.paystack.co/refund')
            && $request['transaction'] === '5550001');
    }

    public function test_the_follow_up_sends_a_paystack_refund_again_only_when_paystack_has_none(): void
    {
        $order = $this->paystackOrder();
        $this->paystackUnreachable = 1;

        $refund = app(RefundService::class)->refund($order);

        // The request never reached Paystack after all.
        $this->paystackRefunds = [];

        $this->laterOn();
        $this->artisan('refunds:follow-up')->assertSuccessful();

        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame(2, $this->paystackPosts);
        $this->assertCount(1, $this->paystackRefunds, 'One refund at Paystack.');
    }

    public function test_a_paystack_that_cannot_be_asked_leaves_the_refund_waiting(): void
    {
        $order = $this->paystackOrder();
        $this->paystackUnreachable = 1;

        $refund = app(RefundService::class)->refund($order);

        $this->paystackListDown = true;

        $this->laterOn();
        $this->artisan('refunds:follow-up')->assertSuccessful();

        // Not a guess either way: still waiting, still holding its tickets,
        // and nothing sent.
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertSame(2, $order->tickets()->where('status', 'valid')->count());
        $this->assertSame(1, $this->paystackPosts);
    }

    public function test_a_follow_up_is_only_asked_once_at_a_time(): void
    {
        $order = $this->stripeOrder();
        $this->stripeUnreachable = 3;
        $refund = app(RefundService::class)->refund($order);

        $this->laterOn();

        // A second worker picking the same refund up finds it already claimed.
        Refund::whereKey($refund->id)->update(['unanswered_at' => now()]);
        app(RefundService::class)->followUp($refund->fresh());

        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertCount(3, $this->stripePosts);
    }
}
