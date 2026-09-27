<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The console's view of an event belongs to the organization that runs it.
 *
 * A published event is public, and the console's endpoints used to take that
 * as leave to answer anybody signed in as an organizer: another organization's
 * orders, tickets issued, arrivals and page views, its reminder schedule and
 * its series, all by id.
 */
class EventConsoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = $this->eventFor($this->org, 'published');
    }

    private function eventFor(Organization $org, string $status): Event
    {
        return Event::create([
            'organization_id' => $org->id,
            'slug' => 'afro-fest-'.Str::lower(Str::random(6)),
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addDays(10),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => $status,
        ]);
    }

    private function signInAs(Organization $org, Role $role): void
    {
        $user = User::factory()->create();

        $org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    /** @return list<string> */
    private function consoleReads(Event $event): array
    {
        return [
            "/api/organizer/events/{$event->id}",
            "/api/organizer/events/{$event->id}/images",
            "/api/organizer/events/{$event->id}/reminders",
            "/api/organizer/events/{$event->id}/series",
        ];
    }

    public function test_another_organizations_owner_cannot_read_a_published_event_in_the_console(): void
    {
        $this->signInAs(Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']), Role::Owner);

        foreach ($this->consoleReads($this->event) as $url) {
            $this->getJson($url)->assertForbidden();
        }

        $this->postJson("/api/organizer/events/{$this->event->id}/duplicate")->assertForbidden();
    }

    public function test_the_organizations_own_people_still_can(): void
    {
        $this->signInAs($this->org, Role::Marketing);

        foreach ($this->consoleReads($this->event) as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_door_staff_keep_the_published_event_they_work_but_not_a_draft(): void
    {
        $this->signInAs($this->org, Role::Door);

        $this->getJson("/api/organizer/events/{$this->event->id}")->assertOk();

        $draft = $this->eventFor($this->org, 'draft');

        $this->getJson("/api/organizer/events/{$draft->id}")->assertForbidden();
    }
}
