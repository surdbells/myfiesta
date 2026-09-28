<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The tickets on somebody's phone: where to go.
 *
 * The app's ticket said "Where: Toronto" — the city, and nothing a door can be
 * found by — while the site's ticket page for the same order named the venue
 * and its street. Both read the same event.
 */
class MyTicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $ada;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $venue = Venue::create([
            'organization_id' => $organization->id,
            'name' => 'Harbourfront Loft',
            'address_line' => '8 Queens Quay West',
            'city' => 'Toronto',
            'country' => 'CA',
            'timezone' => 'America/Toronto',
        ]);

        $this->event = Event::create([
            'organization_id' => $organization->id,
            'venue_id' => $venue->id,
            'slug' => 'qa-free-night',
            'title' => 'QA Free Night',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $type = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 0, 'status' => 'on_sale']);

        $this->ada = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => now()]);

        $order = Order::create([
            'organization_id' => $organization->id,
            'event_id' => $this->event->id,
            'reference' => 'VENUE234',
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada',
            'currency' => 'CAD',
            'subtotal_amount' => 0,
            'total_amount' => 0,
            'net_revenue_amount' => 0,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $type->id,
            'order_id' => $order->id,
            'owner_user_id' => $this->ada->id,
            'owner_email' => 'ada@example.com',
            'holder_name' => 'Ada',
            'code' => strtoupper(Str::random(12)),
            'status' => 'valid',
        ]);
    }

    public function test_a_ticket_says_which_venue_and_where_it_is(): void
    {
        Sanctum::actingAs($this->ada, [TokenAbility::Attendee->value]);

        $this->getJson('/api/me/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.event.city', 'Toronto')
            ->assertJsonPath('data.0.event.venue', ['name' => 'Harbourfront Loft', 'address' => '8 Queens Quay West']);
    }

    public function test_a_ticket_for_a_night_with_no_venue_says_so_rather_than_failing(): void
    {
        $this->event->update(['venue_id' => null]);
        Sanctum::actingAs($this->ada, [TokenAbility::Attendee->value]);

        $this->getJson('/api/me/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.event.venue', null)
            ->assertJsonPath('data.0.event.city', 'Toronto');
    }
}
