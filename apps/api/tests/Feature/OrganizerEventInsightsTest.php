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
 * How a night is going, as the event list and the single event both say it.
 *
 * Two things are pinned here. The single event carries the same counts as
 * the list — its type always claimed it did, and it did not, which nothing
 * noticed until a phone screen read undefined for every figure. And what a
 * night earned is withheld, not zeroed, from a member who may not see money:
 * marketing and door staff fill and run the room and are never shown what it
 * took, which is the rule the overview already kept and the list did not.
 */
class OrganizerEventInsightsTest extends TestCase
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
            'slug' => 'afrobeats-rooftop',
            'title' => 'Afrobeats Rooftop',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $tier = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
            'quantity_available' => 100,
        ]);

        // Two paid orders: three tickets, one of them already through the door.
        foreach ([['ada@example.com', 2], ['bisi@example.com', 1]] as [$email, $tickets]) {
            $order = Order::create([
                'organization_id' => $this->org->id,
                'event_id' => $this->event->id,
                'reference' => strtoupper(Str::random(10)),
                'buyer_email' => $email,
                'buyer_name' => 'Somebody',
                'currency' => 'CAD',
                'subtotal_amount' => 5000 * $tickets,
                'total_amount' => 5000 * $tickets,
                'net_revenue_amount' => 5000 * $tickets,
                'status' => 'paid',
                'paid_at' => now()->subDay(),
            ]);

            for ($n = 0; $n < $tickets; $n++) {
                Ticket::create([
                    'event_id' => $this->event->id,
                    'ticket_type_id' => $tier->id,
                    'order_id' => $order->id,
                    'owner_email' => $email,
                    'code' => strtoupper(Str::random(12)),
                    'status' => $email === 'ada@example.com' && $n === 0 ? 'checked_in' : 'valid',
                ]);
            }
        }
    }

    private function signedInAs(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    public function test_one_event_carries_the_same_counts_as_the_list(): void
    {
        $this->signedInAs(Role::Owner);

        $row = $this->getJson('/api/organizer/events?when=upcoming')->assertOk()->json('data.0');
        $one = $this->getJson("/api/organizer/events/{$this->event->id}")->assertOk()->json();

        foreach (['tickets_issued', 'checked_in', 'orders', 'capacity', 'revenue', 'views', 'last_sale_at'] as $field) {
            $this->assertSame($row[$field], $one[$field], "{$field} differs between the list and the event");
        }

        $this->assertSame(3, $one['tickets_issued']);
        $this->assertSame(1, $one['checked_in']);
        $this->assertSame(2, $one['orders']);
        $this->assertSame(100, $one['capacity']);
        $this->assertSame(['amount' => 15000, 'currency' => 'CAD'], $one['revenue']);

        // And still everything the edit form round-trips.
        $this->assertSame('America/Toronto', $one['timezone']);
        $this->assertArrayHasKey('description', $one);
    }

    public function test_what_a_night_earned_is_withheld_from_members_who_may_not_see_money(): void
    {
        foreach ([Role::Marketing, Role::Door] as $role) {
            $this->signedInAs($role);

            $row = $this->getJson('/api/organizer/events?when=upcoming')->assertOk()->json('data.0');
            $one = $this->getJson("/api/organizer/events/{$this->event->id}")->assertOk()->json();

            // Null, not zero: "earned nothing" and "not yours to know" are
            // different answers, and a zero would claim the first.
            $this->assertNull($row['revenue'], "{$role->value} sees earnings in the list");
            $this->assertNull($one['revenue'], "{$role->value} sees earnings on the event");

            // How full the room is stays: that is their job.
            $this->assertSame(3, $row['tickets_issued']);
            $this->assertSame(1, $one['checked_in']);
        }
    }

    public function test_finance_sees_the_money_it_exists_to_see(): void
    {
        $this->signedInAs(Role::Finance);

        $this->getJson("/api/organizer/events/{$this->event->id}")
            ->assertOk()
            ->assertJsonPath('revenue.amount', 15000);
    }
}
