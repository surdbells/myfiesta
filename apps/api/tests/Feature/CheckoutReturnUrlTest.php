<?php

namespace Tests\Feature;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a payment gateway sends somebody back to.
 *
 * Both URLs were wrong and every checkout test passed, because none of them
 * looked at what was handed to the gateway — only at what came back from our
 * own endpoint.
 *
 * Success pointed at the API's signed orders route, which returns JSON, so a
 * buyer who had just paid landed on a wall of braces. Cancel read a config key
 * that is not defined anywhere and fell back to the API's own host, so somebody
 * who changed their mind got a 404 instead of the event they were looking at.
 *
 * Neither is reachable from a unit test of the controller's response. They are
 * only visible by capturing the options the gateway is called with, which is
 * what this does.
 */
class CheckoutReturnUrlTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $type;

    private CapturingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $org->id,
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

        $this->gateway = new CapturingGateway;

        // Replaced wholesale so nothing reaches a real processor, and so the
        // options handed over can be inspected.
        $registry = new PaymentGatewayRegistry;
        $registry->register($this->gateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);
    }

    private function buy(): void
    {
        $this->postJson("/api/events/{$this->event->slug}/orders", [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
            'buyer' => ['name' => 'Ada Okafor', 'email' => 'ada@example.com'],
            'accept_terms' => true,
        ])->assertCreated();
    }

    public function test_success_returns_the_buyer_to_the_site_not_the_api(): void
    {
        $this->buy();

        $success = $this->gateway->options->successUrl;

        // The API serves no pages. Landing a paid buyer on it gives them raw
        // JSON at the exact moment they most need reassurance.
        $this->assertStringStartsWith(config('app.public_url'), $success);
        $this->assertStringNotContainsString(config('app.url'), $success);
    }

    public function test_success_lands_on_the_order_screen_that_waits_for_the_webhook(): void
    {
        $this->buy();

        $order = Order::firstOrFail();

        // The return itself proves nothing — a signed webhook settles the
        // order — so the buyer has to land somewhere that can say "confirming
        // your payment" and keep asking.
        $this->assertStringEndsWith("/order/{$order->reference}", $this->gateway->options->successUrl);
    }

    public function test_cancelling_returns_to_the_event_rather_than_a_404(): void
    {
        $this->buy();

        $cancel = $this->gateway->options->cancelUrl;

        // Somebody who changed their mind is still a buyer. Sending them to a
        // dead host is how they stay one for somebody else.
        $this->assertSame(config('app.public_url')."/{$this->event->slug}", $cancel);
    }
}

/** Captures the options rather than talking to anything. */
class CapturingGateway implements PaymentGateway
{
    public ?CheckoutOptions $options = null;

    public function name(): string
    {
        // Named after a real gateway because the orders table constrains the
        // column to the ones that exist.
        return 'stripe';
    }

    public function supports(string $currency): bool
    {
        return true;
    }

    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession
    {
        $this->options = $options;

        return new CheckoutSession(
            reference: 'cs_test_123',
            redirectUrl: 'https://checkout.example/session',
            expiresAt: null,
        );
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        return null;
    }

    public function refund(Order $order, int $amountMinorUnits, ?string $reason = null, ?string $idempotencyKey = null): RefundResult
    {
        throw new \LogicException('Not needed here.');
    }
}
