<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
