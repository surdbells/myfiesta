<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Code;
use App\Models\User;

/**
 * Discount and attribution codes.
 *
 * Marketing owns these — a code both costs the organizer money and credits a
 * promoter, so it sits with the people running promotion rather than with
 * whoever happens to be editing the event.
 */
class CodePolicy
{
    public function viewAny(User $user, string $organizationId): bool
    {
        return $user->hasRoleIn($organizationId, Role::Owner, Role::Manager, Role::Marketing, Role::Finance);
    }

    public function view(User $user, Code $code): bool
    {
        return $this->viewAny($user, $code->organization_id);
    }

    public function create(User $user, string $organizationId): bool
    {
        return $user->hasRoleIn($organizationId, Role::Owner, Role::Manager, Role::Marketing);
    }

    public function update(User $user, Code $code): bool
    {
        return $user->hasRoleIn($code->organization_id, Role::Owner, Role::Manager, Role::Marketing);
    }

    /**
     * Codes are deactivated, not deleted.
     *
     * A redeemed code is referenced by orders, and removing it would sever the
     * attribution that explains where those sales came from.
     */
    public function delete(User $user, Code $code): bool
    {
        return $code->redemption_count === 0
            && $user->hasRoleIn($code->organization_id, Role::Owner, Role::Manager);
    }
}
