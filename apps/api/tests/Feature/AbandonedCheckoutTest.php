<?php

namespace Tests\Feature;

use App\Contracts\Payments\CheckoutOptions;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\TicketType;
use App\Services\Checkout\AbandonedCheckouts;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Checkouts that were started and never paid for get closed.
 */
class AbandonedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->published()->create(['organization_id' => $org->id]);
        $this->type = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);
    }

    private function checkout(): Order
    {
        return app(CheckoutService::class)->reserve($this->event, [$this->type->id => 1], 'ada@example.com', 'Ada');
    }

    public function test_a_checkout_left_for_hours_is_closed_and_a_recent_one_is_not(): void
    {
        $abandoned = $this->checkout();
        $this->travel(3)->hours();
        $recent = $this->checkout();

        $this->artisan('checkouts:expire')->expectsOutput('Closed 1 abandoned checkout(s).')->assertSuccessful();

        $this->assertSame('cancelled', $abandoned->fresh()->status);
        $this->assertSame('pending', $recent->fresh()->status);
    }

    public function test_paid_orders_are_never_touched(): void
    {
        $order = $this->checkout();
        $order->update(['gateway' => 'stripe']);
        app(Fulfiller::class)->fulfil($order->refresh());
        $this->travel(3)->hours();

        app(AbandonedCheckouts::class)->expire();

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_payment_that_lands_after_the_order_was_closed_is_still_honoured(): void
    {
        $order = $this->checkout();
        $this->travel(3)->hours();
        app(AbandonedCheckouts::class)->expire();

        // A Paystack bank transfer confirming hours later. The buyer paid.
        $order->update(['gateway' => 'paystack']);
        app(Fulfiller::class)->fulfil($order->refresh());

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_the_stripe_payment_page_expires_in_thirty_minutes_not_a_day(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test', 'url' => 'https://checkout.stripe.test', 'expires_at' => now()->addMinutes(30)->getTimestamp()])]);

        $order = $this->checkout();

        (new StripeGateway('sk_test', 'whsec_test'))->createCheckout($order, new CheckoutOptions(
            successUrl: 'https://myfiesta.test/order/x',
            cancelUrl: 'https://myfiesta.test/e',
            idempotencyKey: 'key',
        ));

        Http::assertSent(fn (Request $request) => abs((int) $request['expires_at'] - now()->addMinutes(30)->getTimestamp()) <= 5);
    }

    public function test_old_expired_holds_are_cleared(): void
    {
        $this->checkout();
        $this->travel(2)->days();

        app(AbandonedCheckouts::class)->expire();

        $this->assertSame(0, \DB::table('inventory_holds')->count());
    }
}
