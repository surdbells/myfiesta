<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Team\TeamRefused;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The join page's two questions: what is this invitation, and accept it.
 */
class InvitationController extends Controller
{
    /**
     * What somebody holding the link is being offered.
     *
     * Answered only for a token that exists. The token was emailed to one
     * address, so what it reveals — the organization, the role, that address —
     * is only ever shown to somebody who already had the email.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = OrganizationInvitation::findByToken($token);

        abort_if($invitation === null, 404, 'This invitation link is not valid.');

        return response()->json([
            'organization' => $invitation->organization->name,
            'role' => $invitation->role->value,
            'role_label' => $invitation->role->label(),
            'role_description' => TeamInvitationMail::describe($invitation->role->value),
            'email' => $invitation->email,
            'invited_by' => $invitation->inviter?->name,
            'state' => $invitation->state(),
            // Whether to offer "sign in" or "create an account" first. The
            // link was sent to this address, so saying so reveals nothing to
            // anyone but its owner.
            'has_account' => User::whereRaw('lower(email) = ?', [strtolower($invitation->email)])->exists(),
        ]);
    }

    /**
     * Join, as the signed-in user.
     *
     * Returns a fresh token: one issued before joining may lack the organizer
     * ability — somebody who until now only bought tickets — and the console
     * should not need a second sign-in to use what was just accepted.
     */
    public function accept(Request $request, string $token, TeamService $team): JsonResponse
    {
        $invitation = OrganizationInvitation::findByToken($token);

        abort_if($invitation === null, 404, 'This invitation link is not valid.');

        $user = $request->user();

        try {
            $organization = $team->accept($invitation, $user);
        } catch (TeamRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        $user->currentAccessToken()?->delete();
        $user->load('organizations');

        return response()->json([
            'token' => $user->createToken('organizer-console', [TokenAbility::Attendee->value, TokenAbility::Organizer->value], now()->addDays(30))->plainTextToken,
            'user' => ['name' => $user->name, 'email' => $user->email],
            'abilities' => [TokenAbility::Attendee->value, TokenAbility::Organizer->value],
            'organizations' => $user->organizations->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'role' => $o->pivot->role,
                'permissions' => $user->permissionsIn($o->id),
            ])->values(),
            'joined' => $organization->id,
        ]);
    }
}
