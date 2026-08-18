<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Settlement;
use App\Models\User;

/**
 * Payouts.
 *
 * Organizers see what they are owed; only platform staff record that money
 * moved. Settlement is manual, so posting one is an assertion about the real
 * world, not an instruction to a payment processor — which is exactly why it
 * needs the narrowest permission in the system.
 */
class SettlementPolicy
{
    public function viewAny(User $user, string $organizationId): bool
    {
        return $user->hasRoleIn($organizationId, Role::Owner, Role::Finance);
    }

    public function view(User $user, Settlement $settlement): bool
    {
        return $this->viewAny($user, $settlement->organization_id);
    }

    /**
     * Recording a settlement is a platform action, never an organizer one.
     *
     * Handled by Filament policies on the admin side; an organizer token must
     * never reach it, which is why this returns false unconditionally rather
     * than checking a role.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /** Settlements are corrected by reversal, like ledger entries. */
    public function update(User $user, Settlement $settlement): bool
    {
        return false;
    }
}
