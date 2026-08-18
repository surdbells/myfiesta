<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Policies\EventPolicy;
use App\Policies\OrganizationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The same role rules, exercised through real persistence.
 *
 * tests/Unit/RoleBoundaryTest covers the policy logic without a database. This
 * one exists because the interesting failures live in the round trip: whether
 * the pivot attaches, whether the role cast survives it, and whether a role
 * read back off a freshly loaded relation is the one that was written.
 *
 * The mobile app carries attendee, organizer, and door modes in one binary, so
 * a door-staff phone has the organizer interface compiled into it. Hiding it is
 * a convenience; these checks are the actual boundary. If they pass by accident
 * the product has the defect it was built to remove.
 */
class RoleBoundaryPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithRole(Role $role): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->members()->attach($user, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        $event = Event::factory()->for($organization)->published()->create();

        return [$user->fresh(['organizations']), $organization, $event];
    }

    public function test_door_staff_can_scan(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Door);

        $this->assertTrue((new EventPolicy)->scan($user, $event));
    }

    public function test_door_staff_cannot_read_the_guest_list(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Door);

        $this->assertFalse(
            (new EventPolicy)->viewGuests($user, $event),
            'Door staff reached the guest list. Scanning must not carry the right to read it.'
        );
    }

    public function test_door_staff_cannot_read_sales(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Door);

        $this->assertFalse((new EventPolicy)->viewSales($user, $event));
    }

    public function test_door_staff_cannot_reach_payout_details(): void
    {
        [$user, $organization] = $this->memberWithRole(Role::Door);

        $this->assertFalse(
            (new OrganizationPolicy)->viewFinancials($user, $organization),
            'Door staff reached banking details.'
        );
    }

    public function test_door_staff_cannot_edit_the_event(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Door);

        $this->assertFalse((new EventPolicy)->update($user, $event));
    }

    public function test_door_is_not_considered_staff(): void
    {
        [$user, $organization] = $this->memberWithRole(Role::Door);

        $this->assertFalse($user->isStaffOf($organization));
    }

    public function test_a_manager_runs_events_but_cannot_see_banking(): void
    {
        [$user, $organization, $event] = $this->memberWithRole(Role::Manager);

        $this->assertTrue((new EventPolicy)->update($user, $event));
        $this->assertTrue((new EventPolicy)->viewGuests($user, $event));
        $this->assertFalse(
            (new OrganizationPolicy)->viewFinancials($user, $organization),
            'A manager reached banking details. Running events must not imply seeing where money lands.'
        );
    }

    public function test_finance_sees_money_but_cannot_edit_events(): void
    {
        [$user, $organization, $event] = $this->memberWithRole(Role::Finance);

        $this->assertTrue((new OrganizationPolicy)->viewFinancials($user, $organization));
        $this->assertTrue((new EventPolicy)->viewSales($user, $event));
        $this->assertFalse((new EventPolicy)->update($user, $event));
    }

    public function test_only_the_owner_manages_members(): void
    {
        foreach ([Role::Manager, Role::Finance, Role::Marketing, Role::Door] as $role) {
            [$user, $organization] = $this->memberWithRole($role);

            $this->assertFalse(
                (new OrganizationPolicy)->manageMembers($user, $organization),
                "{$role->value} was able to manage members."
            );
        }

        [$owner, $ownerOrganization] = $this->memberWithRole(Role::Owner);
        $this->assertTrue((new OrganizationPolicy)->manageMembers($owner, $ownerOrganization));
    }

    public function test_a_stranger_reaches_nothing(): void
    {
        [, $organization, $event] = $this->memberWithRole(Role::Owner);
        $stranger = User::factory()->create();

        $this->assertFalse((new EventPolicy)->update($stranger, $event));
        $this->assertFalse((new EventPolicy)->scan($stranger, $event));
        $this->assertFalse((new EventPolicy)->viewGuests($stranger, $event));
        $this->assertFalse((new OrganizationPolicy)->viewFinancials($stranger, $organization));
        $this->assertFalse($stranger->isStaffOf($organization));
    }

    public function test_a_published_event_is_publicly_visible_but_a_draft_is_not(): void
    {
        [, $organization] = $this->memberWithRole(Role::Owner);

        $published = Event::factory()->for($organization)->published()->create();
        $draft = Event::factory()->for($organization)->create();

        $this->assertTrue((new EventPolicy)->view(null, $published));
        $this->assertFalse((new EventPolicy)->view(null, $draft));
    }
}
