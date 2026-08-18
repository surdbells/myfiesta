<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

/**
 * Ownership, decided in one place.
 *
 * The platform this replaces had an authorization helper that computed a
 * similarity score, discarded it, and returned the literal 100 — so every
 * caller comparing against 100 was told yes. Twenty-nine endpoints trusted it.
 *
 * Policies exist so that "does this person act for this organization?" has
 * exactly one answer, written once, rather than being re-implemented per
 * endpoint and getting it wrong somewhere.
 */
class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->isStaffOf($organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Owner, Role::Manager);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Owner);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Owner);
    }

    /**
     * Banking details and identity documents.
     *
     * Deliberately narrower than update: a manager runs events without ever
     * needing to see where the money lands.
     */
    public function viewFinancials(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Owner, Role::Finance);
    }

    public function manageFinancials(User $user, Organization $organization): bool
    {
        return $user->hasRoleIn($organization, Role::Owner, Role::Finance);
    }
}
