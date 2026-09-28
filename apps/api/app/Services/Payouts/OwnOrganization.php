<?php

namespace App\Services\Payouts;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Money decisions about an organization, made by somebody outside it.
 *
 * Platform staff can also run events. A member of staff who is on an
 * organization's team deciding its payout request, recording a payout to it,
 * crediting it with a repayment or verifying where it is paid is marking their
 * own homework — and each of those is a case where a second person is the
 * whole control. Refused in the services (PayoutRequests, SettlementRecorder,
 * Overdrafts, PayoutVerifier), and the admin's buttons say so before they are
 * pressed.
 *
 * Read from organization_user, not the relation: the relation is the
 * impersonation session's one membership while a staff member acts as an
 * organization, and this is about who they are.
 */
final class OwnOrganization
{
    public const DECIDE_REQUEST = 'You are a member of this organization, so somebody else at myFiesta has to decide its payout request.';

    public const RECORD_PAYOUT = 'You are a member of this organization, so somebody else at myFiesta has to record payouts to it.';

    public const RECORD_REPAYMENT = 'You are a member of this organization, so somebody else at myFiesta has to record money it paid back.';

    public const VERIFY_DETAILS = 'You are a member of this organization, so somebody else has to verify where it is paid.';

    /** @var WeakMap<User, list<string>>|null */
    private static ?WeakMap $teams = null;

    /** Whether this person is on the organization's team, in any role. */
    public static function includes(?User $user, Organization|string|null $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($user === null || $id === null || $id === '') {
            return false;
        }

        return DB::table('organization_user')
            ->where('organization_id', $id)
            ->where('user_id', $user->getKey())
            ->exists();
    }

    /**
     * The same question about whoever is signed in, for a button on every
     * row of a listing: their organizations are read once for the request,
     * not once a row. The services ask the database again when it matters.
     */
    public static function includesCurrentUser(Organization|string|null $organization): bool
    {
        $user = auth()->user();
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if (! $user instanceof User || $id === null || $id === '') {
            return false;
        }

        self::$teams ??= new WeakMap;
        self::$teams[$user] ??= array_values(array_map(
            fn ($organizationId): string => (string) $organizationId,
            DB::table('organization_user')->where('user_id', $user->getKey())->pluck('organization_id')->all(),
        ));

        return in_array((string) $id, self::$teams[$user], true);
    }
}
