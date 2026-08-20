<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may work a door.
 *
 * The route and the controller both documented that an organizer token is
 * accepted — organizers work their own doors constantly — and the middleware
 * only ever accepted `door:{event_id}`. So an organizer standing at their own
 * event got a 403, and the console had no way to scan at all. Nothing covered
 * it, which is why it survived.
 *
 * The pair that matters is the last two tests: opening the door to organizer
 * tokens is only safe because the controller then authorises against this
 * event. Without that, one organizer could scan another's door.
 */
class DoorAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addHour(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);

        $this->ticket = Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $type->id,
            'code' => 'WFY7-F77K4EJW',
            'owner_email' => 'ada@example.com',
            'holder_name' => 'Ada Okafor',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ]);
    }

    private function memberOf(Organization $org, Role $role): User
    {
        $user = User::factory()->create();

        $org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function scan(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/events/{$this->event->id}/scan",
            array_merge(['code' => $this->ticket->code], $body),
        );
    }

    // --- who gets in ---------------------------------------------------------

    public function test_an_organizer_can_scan_their_own_door(): void
    {
        Sanctum::actingAs($this->memberOf($this->org, Role::Manager), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        // The case the console depends on: an organizer signs in normally and
        // stands at their own door, without minting a separate door token to
        // scan their own event.
        $this->scan()
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('admitted', 1);
    }

    public function test_door_staff_can_scan_the_event_their_token_names(): void
    {
        Sanctum::actingAs($this->memberOf($this->org, Role::Door), [
            TokenAbility::doorFor($this->event->id),
        ]);

        $this->scan()->assertOk()->assertJsonPath('accepted', true);
    }

    public function test_a_door_token_is_the_whole_grant_without_a_membership(): void
    {
        // No membership at all — just the token.
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::doorFor($this->event->id)]);

        /*
         * Deliberate. A door token names one event and was minted on purpose
         * for one night, which is what lets a venue hand a phone to whoever is
         * working the door without first creating them an account and adding
         * them to the organization.
         *
         * The organizer path is the one that needs a membership check, because
         * an organizer token names no event.
         */
        $this->scan()->assertOk()->assertJsonPath('accepted', true);
    }

    // --- who does not --------------------------------------------------------

    public function test_a_door_token_for_another_event_is_refused(): void
    {
        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other-night',
            'title' => 'Other Night',
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->memberOf($this->org, Role::Door), [
            TokenAbility::doorFor($other->id),
        ]);

        // Handing a phone to staff for one night must not hand them every door
        // the organization runs.
        $this->scan()->assertForbidden();
        $this->assertSame(0, $this->ticket->fresh()->admitted_count);
    }

    public function test_an_organizer_of_a_different_organization_cannot_scan_this_door(): void
    {
        $rival = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);

        Sanctum::actingAs($this->memberOf($rival, Role::Owner), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        /*
         * The reason opening the door to organizer tokens is safe.
         *
         * The ability says only that somebody organizes something. Without the
         * policy check in the controller, every organizer on the platform could
         * scan every other organizer's event — a far worse hole than the 403 it
         * was fixing.
         */
        $this->scan()->assertForbidden();
        $this->assertSame(0, $this->ticket->fresh()->admitted_count);
    }

    public function test_a_marketing_role_cannot_work_the_door(): void
    {
        Sanctum::actingAs($this->memberOf($this->org, Role::Marketing), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        // Belonging to the organization is not the same as being allowed to
        // decide who comes in.
        $this->scan()->assertForbidden();
    }

    public function test_an_attendee_token_cannot_scan(): void
    {
        Sanctum::actingAs($this->memberOf($this->org, Role::Manager), [
            TokenAbility::Attendee->value,
        ]);

        // A manager holding only an attendee token — the mobile app in its
        // attendee mode — must not reach the door.
        $this->scan()->assertForbidden();
    }

    public function test_a_door_token_still_reaches_nothing_but_the_door(): void
    {
        Sanctum::actingAs($this->memberOf($this->org, Role::Owner), [
            TokenAbility::doorFor($this->event->id),
        ]);

        // The owner of the whole organization, for the life of this token, is
        // only scanning.
        $this->getJson('/api/organizer/events')->assertForbidden();
        $this->getJson("/api/organizer/events/{$this->event->id}/summary")->assertForbidden();
    }
}
