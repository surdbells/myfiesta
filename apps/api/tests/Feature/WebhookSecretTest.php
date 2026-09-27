<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use App\Services\Payments\PaystackGateway;
use App\Services\Payments\StripeGateway;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With no secret, no webhook is believed — in every environment.
 *
 * A signature is an HMAC, and an HMAC keyed with an empty string is one
 * anybody can compute. The production example left STRIPE_WEBHOOK_SECRET
 * empty, so a deployment that took it as written would have marked any order
 * paid for whoever posted the right JSON. The start-up check (Preflight) stops
 * production running like that; this is the layer underneath it, for every
 * environment the check does not guard.
 */
class WebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();

        return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public function test_stripe_believes_nothing_without_its_secret(): void
    {
        $gateway = new StripeGateway(secretKey: 'sk_test_x', webhookSecret: '');
        $payload = '{"id":"evt_1","type":"checkout.session.completed"}';

        $this->assertFalse($gateway->verifySignature($payload, ['stripe-signature' => [$this->stripeSignature($payload, '')]]));
        $this->assertNull($gateway->parseWebhook($payload, ['stripe-signature' => [$this->stripeSignature($payload, '')]]));
    }

    public function test_paystack_believes_nothing_without_its_key(): void
    {
        $gateway = new PaystackGateway(secretKey: '');
        $payload = '{"event":"charge.success","data":{"reference":"ref_1"}}';

        $this->assertFalse($gateway->verifySignature($payload, ['x-paystack-signature' => [hash_hmac('sha512', $payload, '')]]));
    }

    public function test_a_payment_signed_with_nothing_mints_no_tickets(): void
    {
        $this->seed(TaxRateSeeder::class);

        config([
            'payments.stripe.secret_key' => 'sk_test_x',
            'payments.stripe.webhook_secret' => '',
        ]);
        $this->app->forgetInstance(PaymentGatewayRegistry::class);

        $order = $this->order();
        $payload = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $order->gateway_reference,
                'payment_status' => 'paid',
                'amount_total' => $order->total_amount,
                'currency' => 'cad',
            ]],
        ]);

        $this->call('POST', '/webhooks/payments/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, ''), 'CONTENT_TYPE' => 'application/json'],
            content: $payload)
            ->assertStatus(202);

        $this->assertSame('pending', $order->refresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    private function order(): Order
    {
        $org = Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()]);

        $event = Event::create([
            'organization_id' => $org->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Test Event',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 10000,
            'status' => 'on_sale',
        ]);

        $order = app(CheckoutService::class)->reserve($event, [$type->id => 2], 'buyer@example.com', 'Ada');
        $order->update(['gateway' => 'stripe', 'gateway_reference' => 'ref_'.uniqid()]);

        return $order->refresh();
    }
}
