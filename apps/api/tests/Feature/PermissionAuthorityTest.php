<?php

namespace Tests\Feature;

use App\Enums\Permission;
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
 * One authority for what a role may do.
 *
 * The role list for each capability used to be written inline in EventPolicy
 * and then written again, by hand, in the console's session store. They had
 * already drifted: the API granted sales figures to Owner, Manager and Finance
 * and the console showed them to Owner and Finance, so a Manager saw no revenue
 * on an event they were entitled to see.
 *
 * Nothing tested the two against each other, which is why it was silent. These
 * tests are that binding. The first block pins the table itself, so a change to
 * who may do what has to be deliberate; the second proves the API enforces it;
 * the third proves the client is handed the same answer rather than deriving it.
 */
class PermissionAuthorityTest extends TestCase
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
            'country' => 'CA',
            'status' => 'published',
        ]);

        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function signedInAs(Role $role): User
    {
        $user = $this->member($role);

        Sanctum::actingAs($user, [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    // --- the table itself ----------------------------------------------------

    /**
     * Pins the whole map.
     *
     * Deliberately a single hard-coded expectation rather than logic that
     * derives the answer: a test that computes what it checks agrees with any
     * bug in the thing it is checking. Changing who may do what should mean
     * editing this list, on purpose, in the same commit.
     */
    public function test_the_capability_table_is_what_we_think_it_is(): void
    {
        $actual = [];

        foreach (Role::cases() as $role) {
            $actual[$role->value] = Permission::namesForRole($role);
        }

        $this->assertSame([
            'owner' => [
                'events.view', 'events.create', 'events.edit', 'events.publish',
                'events.cancel', 'events.delete',
                'tickets.manage', 'codes.manage',
                'attendees.view', 'door.scan',
                'money.view', 'refunds.process', 'payouts.request',
                // Owners alone decide whose account the payouts land in.
                'payouts.destination',
                'messages.send',
                // Owners alone decide who is on the team, and what the
                // organization is called on a page somebody is paying on, and
                // which other systems its buyers' details are sent to.
                'team.manage', 'organization.brand', 'organization.integrations',
            ],
            'manager' => [
                'events.view', 'events.create', 'events.edit', 'events.publish',
                'tickets.manage', 'codes.manage',
                'attendees.view', 'door.scan',
                'money.view', 'refunds.process',
                'messages.send',
            ],
            'finance' => ['events.view', 'money.view', 'refunds.process', 'payouts.request'],
            'marketing' => ['events.view', 'attendees.view', 'codes.manage', 'messages.send'],
            // Scanning, and nothing else. Being able to check somebody in does
            // not carry the right to read everybody's name and address.
            'door' => ['events.view', 'door.scan'],
        ], $actual);
    }

    public function test_only_an_owner_can_do_the_things_that_cannot_be_undone(): void
    {
        foreach ([Permission::EventsCancel, Permission::EventsDelete] as $permission) {
            $this->assertTrue($this->member(Role::Owner)->hasPermissionIn($this->org->id, $permission));

            foreach ([Role::Manager, Role::Finance, Role::Marketing, Role::Door] as $role) {
                $this->assertFalse(
                    $this->member($role)->hasPermissionIn($this->org->id, $permission),
                    "{$role->value} should not hold {$permission->value}",
                );
            }
        }
    }

    /**
     * Seeing the money is not choosing where it goes.
     *
     * Managers and finance both see the balance, and until this permission
     * existed that was all it took to point the payouts at another account.
     */
    public function test_only_an_owner_can_change_where_payouts_go(): void
    {
        $this->assertTrue($this->member(Role::Owner)->hasPermissionIn($this->org->id, Permission::PayoutsDestination));

        foreach ([Role::Manager, Role::Finance, Role::Marketing, Role::Door] as $role) {
            $this->assertFalse(
                $this->member($role)->hasPermissionIn($this->org->id, Permission::PayoutsDestination),
                "{$role->value} should not be able to change where payouts go",
            );
        }
    }

    public function test_somebody_outside_the_organization_holds_nothing(): void
    {
        $stranger = User::factory()->create();

        $this->assertSame([], $stranger->permissionsIn($this->org->id));

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($stranger->hasPermissionIn($this->org->id, $permission));
        }
    }

    // --- the API enforces it -------------------------------------------------

    public function test_a_manager_can_see_the_money(): void
    {
        $this->signedInAs(Role::Manager);

        // The drift that started this. The API always allowed it; the console
        // hid it, so a manager saw no revenue on their own event.
        $this->getJson("/api/organizer/events/{$this->event->id}/summary")->assertOk();
    }

    public function test_marketing_cannot_see_the_money(): void
    {
        $this->signedInAs(Role::Marketing);

        $this->getJson("/api/organizer/events/{$this->event->id}/summary")->assertForbidden();
    }

    public function test_door_staff_cannot_read_the_guest_list(): void
    {
        $this->signedInAs(Role::Door);

        $this->getJson("/api/organizer/events/{$this->event->id}/guests")->assertForbidden();
    }

    public function test_finance_cannot_change_what_is_on_sale(): void
    {
        $this->signedInAs(Role::Finance);

        $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types", [
            'name' => 'Cheeky',
            'price_amount' => 0,
        ])->assertForbidden();
    }

    public function test_marketing_can_run_codes_but_not_tickets(): void
    {
        $this->signedInAs(Role::Marketing);

        // Codes are marketing's job; prices are not. These shared one gate
        // before the refactor and now name the capability they need.
        $this->getJson("/api/organizer/events/{$this->event->id}/codes")->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/ticket-types", [
            'name' => 'Free for me',
            'price_amount' => 0,
        ])->assertForbidden();
    }

    // --- the client is told, not left to work it out -------------------------

    public function test_signing_in_returns_the_resolved_capability_set(): void
    {
        $user = $this->member(Role::Marketing);
        $user->forceFill(['password' => 'a good long one 9'])->save();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'a good long one 9',
        ])->assertOk();

        // The console checks membership of this array. It does not know what a
        // marketing role implies, and must not learn.
        $this->assertSame(
            ['events.view', 'attendees.view', 'codes.manage', 'messages.send'],
            $response->json('organizations.0.permissions'),
        );
    }

    public function test_registering_returns_the_same_shape_as_signing_in(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
            'password' => 'correct horse 7',
            'password_confirmation' => 'correct horse 7',
            'organization' => 'Danforth Sessions',
        ])->assertCreated();

        // A payload that differs by entry point is a bug waiting for whichever
        // path is tested less.
        $this->assertSame(
            Permission::namesForRole(Role::Owner),
            $response->json('organizations.0.permissions'),
        );

        $this->assertSame(['attendee', 'organizer'], $response->json('abilities'));
    }

    public function test_an_attendee_registering_is_told_it_is_only_an_attendee(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Tunde Bello',
            'email' => 'tunde@example.com',
            'password' => 'correct horse 7',
            'password_confirmation' => 'correct horse 7',
            'attendee' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('abilities', ['attendee'])
            ->assertJsonPath('organizations', []);
    }

    public function test_the_me_endpoint_agrees_with_the_sign_in_payload(): void
    {
        $this->signedInAs(Role::Finance);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('organizations.0.permissions', [
                'events.view', 'money.view', 'refunds.process', 'payouts.request',
            ]);
    }
}
