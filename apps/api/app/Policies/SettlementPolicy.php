<?php

namespace App\Policies;

use App\Enums\Permission;
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
    /**
     * Seeing what has been paid is seeing the money.
     *
     * This used to name Owner and Finance, while the payouts statement — the
     * one place an organizer sees a settlement — lists them for anybody holding
     * money.view, managers included. Two answers to "who sees settlements", and
     * nothing in the app asks this one, so nothing noticed them part.
     *
     * Kept rather than removed, unlike the financials methods OrganizationPolicy
     * lost: those were one word standing for three permissions, and this is one
     * question with one permission behind it. Asked of the Permission enum, the
     * same way PayoutController asks, it is not a second copy of the answer and
     * has nothing to drift from.
     */
    public function viewAny(User $user, string $organizationId): bool
    {
        return $user->hasPermissionIn($organizationId, Permission::MoneyView);
    }

    public function view(User $user, Settlement $settlement): bool
    {
        return $this->viewAny($user, $settlement->organization_id);
    }

    /**
     * Recording a settlement is a platform action, never an organizer one.
     *
     * The admin panel decides that for itself, in SettlementResource's own
     * can* methods, and does not come through here. An organizer token must
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
