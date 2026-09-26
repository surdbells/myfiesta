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
 *
 * Money is not decided here. viewFinancials and manageFinancials used to be —
 * Owner and Finance, both of them — but nothing called either: PayoutController
 * asks the Permission enum itself. So when money was split into seeing it
 * (money.view, which managers hold too), asking for it (payouts.request) and
 * choosing where it goes (payouts.destination, owners only), those two went on
 * answering the old question and their tests went on passing. One word for
 * three permissions cannot be brought into line with them, only made into a
 * second copy that drifts again, so they are gone. Ask hasPermissionIn, as
 * every organization-level screen does.
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
}
