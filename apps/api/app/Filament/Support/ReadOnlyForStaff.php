<?php

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource every member of staff may look at and nobody edits in place.
 *
 * Decided here rather than by the model's policy. The policies in app/Policies
 * answer organizer questions — may this member of this organization see this
 * draft — and a platform employee is not a member of anything, so asking them
 * would refuse support the very records they are there to answer about.
 *
 * Every change these screens make goes through a named action that checks
 * StaffAction and writes to the audit trail. Create, edit and delete are
 * refused outright.
 */
trait ReadOnlyForStaff
{
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        $staff = $user instanceof User && $user->isPlatformStaff() && $user->deleted_at === null;

        return $staff && in_array($action, ['viewAny', 'view'], true)
            ? Response::allow()
            : Response::deny();
    }
}
