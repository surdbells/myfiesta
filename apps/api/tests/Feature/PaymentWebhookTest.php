<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The webhook path, which is the only way an order becomes paid.
 *
 * The previous platform trusted the browser's return from checkout and took the
 * ticket contents from the client alongside it, so a genuine one-dollar payment
 * could be redeemed for any quantity of anything — and an honest buyer who
 * closed the tab after paying got nothing at all. These tests exist so neither
 * can happen again.
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_test_secret';

    private const PAYSTACK_SECRET = 'sk_test_paystack';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        config([
            'payments.stripe.webhook_secret' => self::STRIPE_SECRET,
            'payments.stripe.secret_key' => 'sk_test_stripe',
            'payments.paystack.secret_key' => self::PAYSTACK_SECRET,
        ]);

        // Rebuild so the adapters pick up the test credentials.
        $this->app->forgetInstance(PaymentGatewayRegistry::class);
    }

    private function order(string $currency = 'CAD', int $price = 10000): Order
    {
        $org = Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()]);

        $event = Event::create([
            'organization_id' => $org->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Test Event',
            'currency' => $currency,
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => $currency === 'CAD' ? 'ON' : null,
            'country' => $currency === 'CAD' ? 'CA' : 'NG',
            'status' => 'published',
        ]);

        $type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => $price,
            'status' => 'on_sale',
        ]);

        $order = app(CheckoutService::class)
            ->reserve($event, [$type->id => 2], 'buyer@example.com', 'Ada');

        $order->update([
            'gateway' => $currency === 'NGN' ? 'paystack' : 'stripe',
            'gateway_reference' => 'ref_'.uniqid(),
        ]);

        return $order->refresh();
    }

    private function stripePayload(Order $order, string $type = 'checkout.session.completed'): string
    {
        return json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => $type,
            'data' => ['object' => [
                'id' => $order->gateway_reference,
                'payment_status' => 'paid',
                'amount_total' => $order->total_amount,
                'currency' => strtolower($order->currency),
            ]],
        ]);
    }

    private function stripeSignature(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $digest = hash_hmac('sha256', $timestamp.'.'.$payload, self::STRIPE_SECRET);

        return "t={$timestamp},v1={$digest}";
    }

    public function test_a_signed_payment_issues_tickets(): void
    {
        $order = $this->order();
        $payload = $this->stripePayload($order);

        $this->call(
            'POST',
            '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        )->assertOk();

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->count());
    }

    public function test_an_unsigned_payload_issues_nothing(): void
    {
        $order = $this->order();
        $payload = $this->stripePayload($order);

        // Anyone can POST this. Without a signature it is a stranger's claim,
        // and acting on it is how a free ticket is minted.
        $this->call('POST', '/webhooks/payments/stripe',
            server: ['CONTENT_TYPE' => 'application/json'], content: $payload)
            ->assertStatus(202);

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_forged_signature_issues_nothing(): void
    {
        $order = $this->order();
        $payload = $this->stripePayload($order);
        $forged = 't='.time().',v1='.hash_hmac('sha256', 'anything', 'the-wrong-secret');

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $forged, 'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertStatus(202);

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_replayed_signature_is_refused_once_it_is_stale(): void
    {
        $order = $this->order();
        $payload = $this->stripePayload($order);

        // Genuinely signed, six minutes old. The timestamp is inside the signed
        // material precisely so a captured request cannot be replayed later.
        $stale = $this->stripeSignature($payload, time() - 360);

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $stale, 'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertStatus(202);

        $this->assertSame('pending', $order->refresh()->status);
    }

    public function test_a_tampered_body_invalidates_the_signature(): void
    {
        $order = $this->order();
        $payload = $this->stripePayload($order);
        $signature = $this->stripeSignature($payload);

        // Same signature, body altered to claim a different order.
        $tampered = str_replace($order->gateway_reference, 'ref_someone_else', $payload);

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $tampered)
            ->assertStatus(202);

        $this->assertSame(0, Ticket::count());
    }

    public function test_a_payment_for_the_wrong_amount_is_not_fulfilled(): void
    {
        $order = $this->order();

        $payload = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $order->gateway_reference,
                'payment_status' => 'paid',
                'amount_total' => 100, // a dollar, for a $226 order
                'currency' => 'cad',
            ]],
        ]);

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertOk();

        // Signed, genuine, and wrong. Fulfilling would put revenue in the
        // ledger that was never collected.
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_retried_delivery_does_not_issue_a_second_set(): void
    {
        $order = $this->order();
        $payload = $this->stripePayload($order);
        $signature = $this->stripeSignature($payload);

        $server = ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'];

        // Gateways retry for days, sometimes many times.
        $this->call('POST', '/webhooks/payments/stripe', server: $server, content: $payload)->assertOk();
        $this->call('POST', '/webhooks/payments/stripe', server: $server, content: $payload)->assertOk();
        $this->call('POST', '/webhooks/payments/stripe', server: $server, content: $payload)->assertOk();

        $this->assertSame(2, Ticket::count(), 'One delivery, one set of tickets.');
    }

    public function test_paystack_verifies_with_its_own_algorithm(): void
    {
        $order = $this->order('NGN', 100000);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 12345,
                'reference' => $order->gateway_reference,
                'amount' => $order->total_amount,
                'currency' => 'NGN',
            ],
        ]);

        // SHA512, not SHA256, and over the raw body with no timestamp prefix.
        // Different mechanics from Stripe, identical obligation.
        $signature = hash_hmac('sha512', $payload, self::PAYSTACK_SECRET);

        $this->call('POST', '/webhooks/payments/paystack',
            server: ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertOk();

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->count());
    }

    public function test_a_stripe_signature_does_not_authenticate_a_paystack_webhook(): void
    {
        $order = $this->order('NGN', 100000);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['id' => 1, 'reference' => $order->gateway_reference,
                'amount' => $order->total_amount, 'currency' => 'NGN'],
        ]);

        $this->call('POST', '/webhooks/payments/paystack',
            server: ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, self::STRIPE_SECRET),
                'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertStatus(202);

        $this->assertSame('pending', $order->refresh()->status);
    }

    public function test_an_expired_payment_page_closes_the_order_and_frees_its_places(): void
    {
        $order = $this->order();
        $payload = $this->stripePayload($order, 'checkout.session.expired');

        $this->assertSame(1, $order->holds()->live()->count());

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertOk();

        // Nobody can pay on a page that has closed, so the hold that was
        // outlasting it for a last-second payment has nothing left to wait for.
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(0, $order->holds()->count());
    }

    public function test_an_expired_notice_never_closes_a_paid_order(): void
    {
        $order = $this->order();
        $paid = $this->stripePayload($order);

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($paid), 'CONTENT_TYPE' => 'application/json'],
            content: $paid)
            ->assertOk();

        $expired = $this->stripePayload($order, 'checkout.session.expired');

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($expired), 'CONTENT_TYPE' => 'application/json'],
            content: $expired)
            ->assertOk();

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->count());
    }

    public function test_an_unknown_reference_is_acknowledged_but_ignored(): void
    {
        $payload = json_encode([
            'id' => 'evt_x',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'ref_nothing', 'payment_status' => 'paid',
                'amount_total' => 1000, 'currency' => 'cad']],
        ]);

        // 202 rather than an error: returning 4xx makes gateways retry and
        // eventually disable the endpoint, which would break real payments.
        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertStatus(202);
    }

    // --- notices that arrive late, twice, or out of turn ------------------------

    private function stripe(string $type, array $object, ?string $eventId = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.uniqid(),
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        return $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
            content: $payload);
    }

    /** Paid on Stripe's page, with the payment the page produced kept on the order. */
    private function paidOnStripe(): Order
    {
        $order = $this->order();

        $this->stripe('checkout.session.completed', [
            'id' => $order->gateway_reference,
            'payment_status' => 'paid',
            'payment_intent' => 'pi_'.$order->reference,
            'amount_total' => $order->total_amount,
            'currency' => 'cad',
        ])->assertOk();

        return $order->refresh();
    }

    private function paystack(string $event, array $data): TestResponse
    {
        $payload = json_encode(['event' => $event, 'data' => $data]);

        return $this->call('POST', '/webhooks/payments/paystack',
            server: ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, self::PAYSTACK_SECRET), 'CONTENT_TYPE' => 'application/json'],
            content: $payload);
    }

    private function paidOnPaystack(): Order
    {
        $order = $this->order('NGN', 100000);

        $this->paystack('charge.success', [
            'id' => 896467688,
            'status' => 'success',
            'reference' => $order->gateway_reference,
            'amount' => $order->total_amount,
            'currency' => 'NGN',
        ])->assertOk();

        return $order->refresh();
    }

    /**
     * A Paystack dispute, as Paystack sends it.
     *
     * The dispute has an id of its own and no `reference`; the transaction it
     * is about sits inside it, and transaction_reference is null. The same id
     * comes with the opening and the resolution.
     */
    private function paystackDispute(Order $order, string $event, ?string $resolution = null): TestResponse
    {
        return $this->paystack($event, [
            'id' => 358950,
            'refund_amount' => $order->total_amount,
            'currency' => 'NGN',
            'status' => $resolution === null ? 'awaiting-merchant-feedback' : 'resolved',
            'resolution' => $resolution,
            'domain' => 'live',
            'transaction' => [
                'id' => 896467688,
                'domain' => 'live',
                'status' => 'success',
                'reference' => $order->gateway_reference,
                'amount' => $order->total_amount,
                'gateway_response' => 'Approved',
                'channel' => 'card',
                'currency' => 'NGN',
            ],
            'transaction_reference' => null,
            'category' => 'chargeback',
            'bin' => '123412',
            'last4' => '1234',
            'dueAt' => now()->addDay()->toIso8601String(),
            'resolvedAt' => $resolution === null ? null : now()->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function test_a_paystack_dispute_is_found_by_the_transaction_it_names(): void
    {
        $order = $this->paidOnPaystack();

        // Read by `reference`, which a dispute does not have, this found no
        // order and every Paystack chargeback went unrecorded.
        $this->paystackDispute($order, 'charge.dispute.create')->assertOk();

        $dispute = Dispute::sole();
        $this->assertSame($order->id, $dispute->order_id);
        $this->assertSame('open', $dispute->status);
        $this->assertNotNull($order->fresh()->disputed_at);
    }

    public function test_a_paystack_dispute_resolution_is_not_taken_for_a_repeat_of_its_opening(): void
    {
        $order = $this->paidOnPaystack();

        $this->paystackDispute($order, 'charge.dispute.create')->assertOk();
        // The same dispute id, with the verdict. Keyed on the id alone it was
        // dropped as a duplicate, and a lost chargeback took nothing back.
        $this->paystackDispute($order, 'charge.dispute.resolve', 'merchant-accepted')->assertOk();

        $this->assertSame('lost', Dispute::sole()->status);
        $this->assertSame(1, LedgerEntry::where('order_id', $order->id)->where('type', 'chargeback')->count());
        $this->assertSame(2, Ticket::where('order_id', $order->id)->where('status', 'void')->count());
    }

    public function test_a_paystack_dispute_resolved_for_the_merchant_is_won(): void
    {
        $order = $this->paidOnPaystack();

        $this->paystackDispute($order, 'charge.dispute.create')->assertOk();
        $this->paystackDispute($order, 'charge.dispute.resolve', 'declined')->assertOk();

        $this->assertSame('won', Dispute::sole()->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->where('status', 'valid')->count());
    }

    public function test_a_chargeback_paid_back_as_a_refund_is_not_counted_twice(): void
    {
        $order = $this->paidOnPaystack();

        $this->paystackDispute($order, 'charge.dispute.create')->assertOk();
        $this->paystackDispute($order, 'charge.dispute.resolve', 'merchant-accepted')->assertOk();

        // Paystack pays the buyer back as a refund, and announces that too.
        $this->paystack('refund.processed', [
            'status' => 'processed',
            'transaction_reference' => $order->gateway_reference,
            'refund_reference' => '132013318360',
            'amount' => $order->total_amount,
            'currency' => 'NGN',
        ])->assertOk();

        // The chargeback took the money off once; the refund does not again.
        $this->assertSame(0, Refund::count());
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
    }

    public function test_a_late_failure_notice_never_undoes_a_payment(): void
    {
        $order = $this->paidOnStripe();

        // The first card was declined, the second went through, and the
        // decline's notice was retried last.
        $this->stripe('payment_intent.payment_failed', [
            'id' => 'pi_'.$order->reference,
            'object' => 'payment_intent',
            'amount' => $order->total_amount,
            'currency' => 'cad',
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, Ticket::where('order_id', $order->id)->where('status', 'valid')->count());
    }

    public function test_a_paystack_failure_after_success_leaves_the_order_paid(): void
    {
        Log::spy();

        $order = $this->paidOnPaystack();

        $this->paystack('charge.failed', [
            'id' => 896467689,
            'status' => 'failed',
            'reference' => $order->gateway_reference,
            'amount' => $order->total_amount,
            'currency' => 'NGN',
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);

        // Refused, and written down where a processor sending things out of
        // order will show up.
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => str_contains($message, 'backwards')
            && $context['from'] === 'paid'
            && $context['to'] === 'failed');
    }

    public function test_a_failure_before_payment_still_fails_the_order(): void
    {
        $order = $this->order('NGN', 100000);

        $this->paystack('charge.failed', [
            'id' => 1,
            'status' => 'failed',
            'reference' => $order->gateway_reference,
            'amount' => $order->total_amount,
            'currency' => 'NGN',
        ])->assertOk();

        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_an_expired_notice_never_reopens_a_refunded_order(): void
    {
        $order = $this->paidOnStripe();
        $order->update(['status' => 'refunded']);

        $this->stripe('checkout.session.expired', ['id' => $order->gateway_reference])->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_the_moves_a_notice_may_make(): void
    {
        $moves = [
            ['pending', 'paid', true],
            ['pending', 'failed', true],
            ['pending', 'cancelled', true],
            ['cancelled', 'paid', true],
            ['paid', 'partially_refunded', true],
            ['paid', 'refunded', true],
            ['partially_refunded', 'refunded', true],
            ['paid', 'pending', false],
            ['paid', 'failed', false],
            ['paid', 'cancelled', false],
            ['partially_refunded', 'paid', false],
            ['refunded', 'paid', false],
            ['refunded', 'partially_refunded', false],
            ['refunded', 'cancelled', false],
            ['failed', 'paid', false],
            ['cancelled', 'pending', false],
        ];

        foreach ($moves as [$from, $to, $allowed]) {
            $this->assertSame($allowed, (new Order)->forceFill(['status' => $from])->mayBecome($to), "{$from} → {$to}");
        }
    }
}
