<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\DoorPass;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Door passes: a link that makes one phone a scanner for one event.
 *
 * The tests that matter most are the boundary ones. The token a pass mints
 * belongs to the member who issued it, so everything that member can reach is
 * one mistake away from the phone at the door.
 */
class DoorPassTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private Event $event;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->manager = $this->member(Role::Manager);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(8),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
            'min_age' => 19,
            'id_required' => true,
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
            'owner_email' => 'guest@example.com',
            'holder_name' => 'Guest One',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ]);
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);

        return $user->fresh()->load('organizations');
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    /** @return string the secret from the link */
    private function issue(string $label = 'Front gate', ?User $by = null): string
    {
        $this->actAs($by ?? $this->manager);

        $response = $this->postJson("/api/organizer/events/{$this->event->id}/door-passes", ['label' => $label])
            ->assertCreated()
            ->assertJsonPath('data.state', 'waiting');

        // The same link as a code the phone can scan off the organizer's screen.
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('qr'));

        $link = $response->json('link');

        return Str::afterLast($link, '/door-pass/');
    }

    /** Open the link as a phone with no session at all. */
    private function claim(string $secret): string
    {
        $this->forgetAuth();

        return $this->postJson("/api/door-passes/{$secret}/claim")->assertOk()->json('token');
    }

    private function forgetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function asPhone(string $token): static
    {
        $this->forgetAuth();

        return $this->withToken($token);
    }

    public function test_a_manager_issues_a_pass_and_the_phone_scans_with_it(): void
    {
        $secret = $this->issue('Front gate');

        // What the phone is shown before it commits to opening the link.
        $this->forgetAuth();
        $this->getJson("/api/door-passes/{$secret}")
            ->assertOk()
            ->assertJsonPath('label', 'Front gate')
            ->assertJsonPath('state', 'waiting')
            ->assertJsonPath('event.title', 'Afro Fest');

        $response = $this->postJson("/api/door-passes/{$secret}/claim")
            ->assertOk()
            ->assertJsonPath('event.id', $this->event->id)
            ->assertJsonPath('event.min_age', 19)
            ->assertJsonPath('event.id_required', true);

        $this->asPhone($response->json('token'))
            ->postJson("/api/events/{$this->event->id}/scan", ['code' => $this->ticket->code])
            ->assertOk()
            ->assertJsonPath('accepted', true);

        $this->asPhone($response->json('token'))
            ->getJson("/api/events/{$this->event->id}/door-list")
            ->assertOk();

        $pass = DoorPass::sole();
        $scan = TicketScan::sole();
        $this->assertSame($pass->id, $scan->door_pass_id);
        // The member who vouched for the phone is who Sanctum sees scanning.
        $this->assertSame($this->manager->id, $scan->scanned_by);

        $this->actAs($this->manager);
        $this->getJson("/api/organizer/events/{$this->event->id}/door-passes")
            ->assertOk()
            ->assertJsonPath('data.0.state', 'active')
            ->assertJsonPath('data.0.scans', 1)
            ->assertJsonPath('data.0.admitted_scans', 1)
            ->assertJsonMissingPath('data.0.secret_hash');
    }

    public function test_the_link_works_once(): void
    {
        $secret = $this->issue();
        $this->claim($secret);

        $this->forgetAuth();
        $this->postJson("/api/door-passes/{$secret}/claim")
            ->assertStatus(410)
            ->assertJsonPath('message', 'This link has already been opened on another phone. Each phone needs its own link — ask for a new one.');

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_a_door_pass_reaches_nothing_but_its_own_door(): void
    {
        $secret = $this->issue();
        $token = $this->claim($secret);

        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other-night',
            'title' => 'Other night',
            'currency' => 'CAD',
            'starts_at' => now()->addDay(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->asPhone($token)->postJson("/api/events/{$other->id}/scan", ['code' => $this->ticket->code])->assertForbidden();
        $this->asPhone($token)->getJson('/api/organizer/events')->assertForbidden();
        $this->asPhone($token)->getJson("/api/organizer/events/{$this->event->id}/guests")->assertForbidden();
        $this->asPhone($token)->getJson('/api/organizer/payouts')->assertForbidden();

        // The issuer's own account. Sanctum sees the phone as the issuer, so
        // these are the ones that would leak if the scope check missed them.
        $this->asPhone($token)->getJson('/api/auth/me')->assertForbidden();
        $this->asPhone($token)->patchJson('/api/auth/profile', ['name' => 'Renamed'])->assertForbidden();
        $this->asPhone($token)->postJson('/api/auth/password', [
            'current_password' => 'password', 'password' => 'new password 42', 'password_confirmation' => 'new password 42',
        ])->assertForbidden();
        $this->asPhone($token)->getJson('/api/me/tickets')->assertForbidden();

        $this->assertNotSame('Renamed', $this->manager->fresh()->name);
    }

    public function test_revoking_stops_the_phone_at_its_next_scan(): void
    {
        $secret = $this->issue();
        $token = $this->claim($secret);
        $pass = DoorPass::sole();

        $this->actAs($this->manager);
        $this->deleteJson("/api/organizer/events/{$this->event->id}/door-passes/{$pass->id}")->assertOk();

        $this->asPhone($token)
            ->postJson("/api/events/{$this->event->id}/scan", ['code' => $this->ticket->code])
            ->assertUnauthorized();

        $this->assertSame('revoked', $pass->fresh()->state());
    }

    public function test_a_revoked_link_that_was_never_opened_cannot_be_opened(): void
    {
        $secret = $this->issue();

        $this->actAs($this->manager);
        $this->deleteJson("/api/organizer/events/{$this->event->id}/door-passes/".DoorPass::sole()->id)->assertOk();

        $this->forgetAuth();
        $this->postJson("/api/door-passes/{$secret}/claim")->assertStatus(410);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_pass_expires_with_the_night(): void
    {
        $secret = $this->issue();
        $token = $this->claim($secret);

        $pass = DoorPass::sole();
        $this->assertSame($this->event->fresh()->ends_at->copy()->addHours(3)->timestamp, $pass->expires_at->timestamp);

        $this->travelTo($pass->expires_at->copy()->addMinute());

        $this->asPhone($token)
            ->postJson("/api/events/{$this->event->id}/scan", ['code' => $this->ticket->code])
            ->assertUnauthorized();
    }

    public function test_no_pass_lives_longer_than_two_days(): void
    {
        $this->event->update(['starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(12)]);

        $this->issue();

        $this->assertTrue(DoorPass::sole()->expires_at->lessThanOrEqualTo(now()->addHours(48)));
    }

    public function test_no_pass_for_a_night_that_is_over_or_cancelled(): void
    {
        $this->actAs($this->manager);

        $this->event->update(['starts_at' => now()->subDay(), 'ends_at' => now()->subHours(20)]);
        $this->postJson("/api/organizer/events/{$this->event->id}/door-passes", ['label' => 'Late'])->assertStatus(422);

        $this->event->update(['starts_at' => now()->addDay(), 'ends_at' => null, 'status' => 'cancelled', 'cancelled_at' => now()]);
        $this->postJson("/api/organizer/events/{$this->event->id}/door-passes", ['label' => 'Late'])->assertStatus(422);

        $this->assertSame(0, DoorPass::count());
    }

    public function test_door_staff_and_marketing_cannot_hand_out_the_door(): void
    {
        foreach ([Role::Door, Role::Marketing, Role::Finance] as $role) {
            $this->actAs($this->member($role));
            $this->postJson("/api/organizer/events/{$this->event->id}/door-passes", ['label' => 'Mine'])->assertForbidden();
            $this->getJson("/api/organizer/events/{$this->event->id}/door-passes")->assertForbidden();
        }

        $this->assertSame(0, DoorPass::count());
    }

    public function test_another_organization_cannot_see_or_revoke_passes(): void
    {
        $this->issue();
        $pass = DoorPass::sole();

        $rival = Organization::create(['name' => 'Rival', 'slug' => 'rival']);
        $stranger = User::factory()->create();
        $rival->members()->attach($stranger->id, ['id' => (string) Str::uuid(), 'role' => 'owner', 'accepted_at' => now()]);

        $this->actAs($stranger);
        $this->getJson("/api/organizer/events/{$this->event->id}/door-passes")->assertForbidden();
        $this->deleteJson("/api/organizer/events/{$this->event->id}/door-passes/{$pass->id}")->assertForbidden();

        $this->assertNull($pass->fresh()->revoked_at);
    }

    public function test_removing_the_issuer_ends_their_passes(): void
    {
        $owner = $this->member(Role::Owner);
        $secret = $this->issue();
        $token = $this->claim($secret);
        $unopened = $this->issue('Side door');

        $this->actAs($owner);
        $this->deleteJson("/api/organizer/team/members/{$this->manager->id}")->assertOk();

        $this->asPhone($token)
            ->postJson("/api/events/{$this->event->id}/scan", ['code' => $this->ticket->code])
            ->assertUnauthorized();

        $this->forgetAuth();
        $this->postJson("/api/door-passes/{$unopened}/claim")->assertStatus(410);

        $this->assertSame(['ended', 'ended'], DoorPass::orderBy('created_at')->get()->map->state()->all());
    }

    public function test_an_issuer_who_loses_the_door_takes_their_phones_with_them(): void
    {
        $owner = $this->member(Role::Owner);
        $token = $this->claim($this->issue());

        $this->actAs($owner);
        $this->patchJson("/api/organizer/team/members/{$this->manager->id}", ['role' => 'marketing'])->assertOk();

        $this->asPhone($token)
            ->postJson("/api/events/{$this->event->id}/scan", ['code' => $this->ticket->code])
            ->assertForbidden();
    }

    public function test_signing_out_on_the_phone_throws_the_pass_away(): void
    {
        $token = $this->claim($this->issue());

        $this->asPhone($token)->postJson('/api/auth/logout')->assertSuccessful();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame('ended', DoorPass::sole()->state());
    }

    public function test_the_secret_is_never_stored(): void
    {
        $secret = $this->issue();

        $this->assertDatabaseMissing('door_passes', ['secret_hash' => $secret]);
        $this->assertSame(hash('sha256', $secret), DoorPass::sole()->secret_hash);
        $this->assertStringNotContainsString($secret, \App\Models\AuditLog::query()->get()->toJson());
    }
}
