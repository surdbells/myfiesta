<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the tickets screen is actually for.
 *
 * The list used to return a name, a price and a status, which is enough to
 * render a form and not enough to answer the only question anybody opens it
 * with: how is this one selling. Counting from the console is not an option —
 * it would have to load every ticket on the event to group them by type.
 */
class TicketTypeInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
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

        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function type(array $attributes = []): TicketType
    {
        return TicketType::create(array_merge([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
            'quantity_available' => 100,
        ], $attributes));
    }

    private function issue(TicketType $type, int $count, string $status = 'valid'): void
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'tax_amount' => 0,
            'tax_inclusive' => false,
            'net_revenue_amount' => 5000,
            'service_charge_amount' => 400,
            'total_amount' => 5400,
            'status' => 'paid',
        ]);

        for ($i = 0; $i < $count; $i++) {
            Ticket::create([
                'code' => strtoupper(Str::random(10)),
                'event_id' => $this->event->id,
                'ticket_type_id' => $type->id,
                'order_id' => $order->id,
                'owner_email' => 'ada@example.com',
                'status' => $status,
                'admits' => 1,
            ]);
        }
    }

    private function list(): array
    {
        return $this->getJson("/api/organizer/events/{$this->event->id}/ticket-types")
            ->assertOk()
            ->json('data');
    }

    public function test_a_type_reports_what_has_sold_and_what_is_left(): void
    {
        $type = $this->type();
        $this->issue($type, 12);

        $row = $this->list()[0];

        $this->assertSame(12, $row['sold']);
        $this->assertSame(100, $row['quantity_available']);
        $this->assertSame(88, $row['remaining']);
    }

    public function test_a_refunded_ticket_gives_its_place_back(): void
    {
        $type = $this->type();
        $this->issue($type, 5);
        $this->issue($type, 3, 'refunded');

        $row = $this->list()[0];

        // Eight tickets exist and three of them will not be walking in. The
        // place is free, and a screen that says otherwise stops a sale.
        $this->assertSame(5, $row['sold']);
        $this->assertSame(95, $row['remaining']);
    }

    public function test_an_unlimited_type_has_no_remaining_rather_than_zero(): void
    {
        $type = $this->type(['name' => 'Guest list', 'quantity_available' => null]);
        $this->issue($type, 4);

        $row = $this->list()[0];

        $this->assertSame(4, $row['sold']);
        $this->assertNull($row['quantity_available']);
        // Null, not 0. Rendering a missing capacity as zero tells an organizer
        // their guest list is sold out.
        $this->assertNull($row['remaining']);
    }

    public function test_remaining_never_goes_negative(): void
    {
        // Comps and door sales can push issued past the number that was set.
        $type = $this->type(['quantity_available' => 10]);
        $this->issue($type, 14);

        $row = $this->list()[0];

        $this->assertSame(14, $row['sold']);
        $this->assertSame(0, $row['remaining']);
    }

    public function test_the_sale_window_comes_back_so_it_can_be_edited(): void
    {
        $this->type([
            'sales_start_at' => now()->addDay(),
            'sales_end_at' => now()->addWeek(),
        ]);

        $row = $this->list()[0];

        // Accepted by the update endpoint and never returned by the list, so
        // an organizer could set a window once and never see it again.
        $this->assertNotNull($row['sales_start_at']);
        $this->assertNotNull($row['sales_end_at']);
    }
}
