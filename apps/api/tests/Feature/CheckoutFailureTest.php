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

    private function order(int $quantity = 1, ?string $retryOf = null): TestResponse
    {
        return $this->postJson('/api/events/gateway-down/orders', [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => $quantity]],
            'buyer' => ['name' => 'Ada', 'email' => 'ada@example.com'],
            'accept_terms' => true,
            'retry_of' => $retryOf,
        ]);
    }

    /** Places held right now, across every order. */
    private function held(): int
    {
        return (int) InventoryHold::where('expires_at', '>', now())->sum('quantity');
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

    /**
     * Pressing the button again held the same places a second time.
     *
     * Found in a browser during a processor outage: each press opened a new
     * pending order with a new forty-minute hold, so a buyer of three held
     * six, then nine, and a tier that was nearly gone read as sold out to
     * everybody else. The retry names the attempt the 502 came back with,
     * and that attempt's hold becomes the new order's.
     */
    public function test_a_retry_takes_over_the_failed_attempts_hold_rather_than_holding_again(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);

        $first = $this->order(3)->assertStatus(502)->json('reference');
        $second = $this->order(3, retryOf: $first)->assertStatus(502)->json('reference');
        $third = $this->order(3, retryOf: $second)->assertStatus(502)->json('reference');

        $this->assertSame(3, $this->held());
        $this->assertSame(1, Order::where('status', 'pending')->count());
        $this->assertDatabaseHas('orders', ['reference' => $third, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['reference' => $first, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['reference' => $second, 'status' => 'cancelled']);
    }

    /** The last places stay the buyer's across the retry: they are never free in between. */
    public function test_a_retry_for_the_last_places_is_not_refused_as_sold_out(): void
    {
        $this->type->update(['quantity_available' => 2]);

        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);

        $first = $this->order(2)->assertStatus(502)->json('reference');

        // Without the hand-over this was refused as sold out: the buyer's own
        // hold had taken the last two places.
        $this->order(2, retryOf: $first)->assertStatus(502);

        $this->assertSame(2, $this->held());
    }

    /**
     * Only an attempt nobody can pay is closed by naming it.
     *
     * One with a payment page open may be being paid for at this moment, and
     * a paid one is somebody's tickets; naming either as retry_of changes
     * nothing about it, and the new order is placed beside it as before.
     */
    public function test_retry_of_leaves_an_order_that_could_still_be_paid_alone(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);

        $opened = $this->order()->assertStatus(502)->json('reference');
        Order::where('reference', $opened)->update(['gateway' => 'stripe', 'gateway_reference' => 'cs_test_open']);

        $this->order(retryOf: $opened)->assertStatus(502);

        $this->assertDatabaseHas('orders', ['reference' => $opened, 'status' => 'pending']);
        $this->assertSame(2, $this->held());
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
