<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard's figures.
 *
 * Two things are worth pinning here. The takings are a permission rather than
 * a screen, so the same endpoint has to answer door staff without them. And
 * the attention list only ever contains real conditions — a list that always
 * has something in it is a list nobody reads.
 */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
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

    private function event(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'organization_id' => $this->org->id,
            'slug' => 'e-'.Str::random(8),
            'title' => 'A Night',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ], $attributes));
    }

    private function overview(): array
    {
        return $this->getJson('/api/organizer/overview')->assertOk()->json();
    }

    public function test_an_owner_sees_the_takings(): void
    {
        $this->signedInAs(Role::Owner);
        $this->event();

        $overview = $this->overview();

        $this->assertNotNull($overview['money']);
        $this->assertArrayHasKey('balance', $overview['money']);
        $this->assertSame('CAD', $overview['currency']);
    }

    public function test_door_staff_get_the_same_dashboard_without_the_money(): void
    {
        $this->signedInAs(Role::Door);
        $this->event();

        $overview = $this->overview();

        // Null, not zero, and not a different endpoint. Zero would read as
        // "you are owed nothing", which is a statement about the business
        // rather than about what this person may see.
        $this->assertNull($overview['money']);
        $this->assertSame(1, $overview['selling']['upcoming_events']);
    }

    public function test_a_published_event_with_nothing_on_sale_is_flagged(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event(['title' => 'Nothing To Buy']);

        $overview = $this->overview();

        // The worst state an event can be in: listed, visible, unbuyable.
        $this->assertCount(1, $overview['attention']);
        $this->assertSame($event->id, $overview['attention'][0]['event_id']);
        $this->assertSame('danger', $overview['attention'][0]['severity']);
    }

    public function test_an_event_that_is_actually_selling_is_not_flagged(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event();

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
            'quantity_available' => 100,
        ]);

        $this->assertSame([], $this->overview()['attention']);
    }

    public function test_the_next_event_reports_a_capacity_only_when_there_is_one(): void
    {
        $this->signedInAs(Role::Owner);
        $event = $this->event();

        TicketType::create([
            'event_id' => $event->id, 'name' => 'General', 'price_amount' => 5000,
            'status' => 'on_sale', 'quantity_available' => 40,
        ]);

        $this->assertSame(40, $this->overview()['next_event']['capacity']);

        // One unlimited type and the whole capacity is unknowable. A
        // percentage against a total that does not exist is a figure somebody
        // would plan a door around.
        TicketType::create([
            'event_id' => $event->id, 'name' => 'Guest list', 'price_amount' => 0,
            'status' => 'on_sale', 'quantity_available' => null,
        ]);

        $this->assertNull($this->overview()['next_event']['capacity']);
    }

    public function test_an_organization_with_nothing_on_says_so_rather_than_erroring(): void
    {
        $this->signedInAs(Role::Owner);

        $overview = $this->overview();

        $this->assertNull($overview['next_event']);
        $this->assertSame(0, $overview['selling']['upcoming_events']);
        $this->assertSame([], $overview['attention']);
    }
}
