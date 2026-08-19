<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a buyer sees when the payment provider is unreachable.
 *
 * Found by driving a real browser rather than by reading the code: with no
 * Stripe key configured, the raw provider error reached the checkout form and
 * told the buyer "You did not provide an API key". That is our configuration
 * leaking to a stranger, phrased so it sounds like their fault.
 */
class CheckoutFailureTest extends TestCase
{
    use RefreshDatabase;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        $event = Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'gateway-down',
            'title' => 'Gateway Down',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function order(): TestResponse
    {
        return $this->postJson('/api/events/gateway-down/orders', [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
            'buyer' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ]);
    }

    public function test_a_provider_failure_does_not_leak_its_error_to_the_buyer(): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'error' => ['message' => 'You did not provide an API key.'],
            ], 401),
        ]);

        $response = $this->order()->assertStatus(502);

        $message = $response->json('message');

        // Nothing about keys, providers, or our configuration.
        $this->assertStringNotContainsString('API key', $message);
        $this->assertStringNotContainsString('Stripe', $message);

        // And the one thing they actually want to know at this moment.
        $this->assertStringContainsString('Nothing has been charged', $message);
    }

    public function test_the_order_and_its_hold_survive_a_provider_failure(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);

        $reference = $this->order()->assertStatus(502)->json('reference');

        // Their stock stays theirs for the hold window, so retrying costs them
        // nothing and they are not sent to the back of a queue for our failure.
        $this->assertDatabaseHas('orders', ['reference' => $reference, 'status' => 'pending']);
        $this->assertSame(1, InventoryHold::where('expires_at', '>', now())->count());
    }

    public function test_a_failed_order_is_never_marked_paid(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);

        $this->order()->assertStatus(502);

        // Only a verified webhook may do that, and none arrived.
        $this->assertSame(0, Order::where('status', 'paid')->count());
        $this->assertSame(0, Ticket::count());
    }
}
