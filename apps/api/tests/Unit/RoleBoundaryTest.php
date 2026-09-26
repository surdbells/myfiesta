<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Policies\EventPolicy;
use App\Policies\OrganizationPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * The door boundary, asserted without touching a database.
 *
 * Authorization here is pure: it reads a role off a loaded relation and
 * answers. Building the objects in memory keeps these checks runnable
 * anywhere, which matters because they are the rules that stop a door-staff
 * phone from becoming an organizer console — the defect this platform was
 * rebuilt to remove.
 *
 * Tests that exercise the same rules through real persistence live in
 * tests/Feature and need Postgres.
 */
class RoleBoundaryTest extends TestCase
{
    private const ORG_ID = '11111111-1111-1111-1111-111111111111';

    /** @return array{User, Organization, Event} */
    private function memberWithRole(?Role $role): array
    {
        $organization = new Organization;
        $organization->id = self::ORG_ID;

        $user = new User;
        $user->id = '22222222-2222-2222-2222-222222222222';
        $user->email = 'staff@example.com';

        $memberships = new Collection;

        if ($role !== null) {
            $pivot = new OrganizationUser;
            $pivot->role = $role;
            $organization->setRelation('pivot', $pivot);
            $memberships->push($organization);
        }

        $user->setRelation('organizations', $memberships);

        $event = new Event;
        $event->id = '33333333-3333-3333-3333-333333333333';
        $event->organization_id = self::ORG_ID;
        $event->status = 'published';

        return [$user, $organization, $event];
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

        // The permissions the payouts endpoints check, asked the way they ask.
        $this->assertFalse(
            $user->hasPermissionIn($organization, Permission::MoneyView),
            'Door staff reached the payouts screen, and the banking details on it.'
        );
        $this->assertFalse($user->hasPermissionIn($organization, Permission::PayoutsDestination));
    }

    public function test_door_staff_cannot_edit_the_event(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Door);

        $this->assertFalse((new EventPolicy)->update($user, $event));
    }

    public function test_door_is_not_counted_as_staff(): void
    {
        [$user, $organization] = $this->memberWithRole(Role::Door);

        $this->assertFalse($user->isStaffOf($organization));
    }

    /**
     * A manager sees the money — and, masked, where it goes — but cannot move it.
     *
     * This used to assert that a manager could not see banking details at all,
     * against a policy method nothing called. The payouts screen has shown
     * managers the masked destination since money.view was theirs; the line
     * that matters is that they cannot point it anywhere else.
     */
    public function test_a_manager_runs_events_but_cannot_redirect_payouts(): void
    {
        [$user, $organization, $event] = $this->memberWithRole(Role::Manager);

        $this->assertTrue((new EventPolicy)->update($user, $event));
        $this->assertTrue((new EventPolicy)->viewGuests($user, $event));
        $this->assertTrue($user->hasPermissionIn($organization, Permission::MoneyView));
        $this->assertFalse(
            $user->hasPermissionIn($organization, Permission::PayoutsDestination),
            'A manager could redirect payouts. Running events must not imply choosing where the money lands.'
        );
        $this->assertFalse($user->hasPermissionIn($organization, Permission::PayoutsRequest));
    }

    public function test_finance_sees_money_and_asks_for_it_but_cannot_redirect_it_or_edit_events(): void
    {
        [$user, $organization, $event] = $this->memberWithRole(Role::Finance);

        $this->assertTrue($user->hasPermissionIn($organization, Permission::MoneyView));
        $this->assertTrue($user->hasPermissionIn($organization, Permission::PayoutsRequest));
        $this->assertFalse($user->hasPermissionIn($organization, Permission::PayoutsDestination));
        $this->assertTrue((new EventPolicy)->viewSales($user, $event));
        $this->assertFalse((new EventPolicy)->update($user, $event));
    }

    public function test_marketing_messages_guests_but_cannot_see_sales(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Marketing);

        $this->assertTrue((new EventPolicy)->message($user, $event));
        $this->assertFalse((new EventPolicy)->viewSales($user, $event));
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

    public function test_a_non_member_reaches_nothing(): void
    {
        [$stranger, $organization, $event] = $this->memberWithRole(null);

        $this->assertNull($stranger->roleIn($organization));
        $this->assertFalse($stranger->isStaffOf($organization));
        $this->assertFalse((new EventPolicy)->update($stranger, $event));
        $this->assertFalse((new EventPolicy)->scan($stranger, $event));
        $this->assertFalse((new EventPolicy)->viewGuests($stranger, $event));
        $this->assertFalse($stranger->hasPermissionIn($organization, Permission::MoneyView));
        $this->assertFalse($stranger->hasPermissionIn($organization, Permission::PayoutsDestination));
    }

    public function test_a_role_in_one_organization_grants_nothing_in_another(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Owner);

        $someoneElsesEvent = new Event;
        $someoneElsesEvent->id = '44444444-4444-4444-4444-444444444444';
        $someoneElsesEvent->organization_id = '99999999-9999-9999-9999-999999999999';
        $someoneElsesEvent->status = 'published';

        $this->assertTrue((new EventPolicy)->update($user, $event));
        $this->assertFalse(
            (new EventPolicy)->update($user, $someoneElsesEvent),
            'Owning one organization granted access to another.'
        );
    }

    public function test_drafts_are_private_but_published_events_are_not(): void
    {
        [$user, , $event] = $this->memberWithRole(Role::Manager);

        $draft = new Event;
        $draft->id = '55555555-5555-5555-5555-555555555555';
        $draft->organization_id = self::ORG_ID;
        $draft->status = 'draft';

        $this->assertTrue((new EventPolicy)->view(null, $event));
        $this->assertFalse((new EventPolicy)->view(null, $draft));
        $this->assertTrue((new EventPolicy)->view($user, $draft));
    }
}
