<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Services\Impersonation\Impersonation;
use App\Services\Impersonation\ImpersonationRefused;
use App\Services\Impersonation\WhileImpersonating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The console's side of a staff session.
 *
 * Started in the admin panel (ImpersonateOrganizationAction), never here:
 * there is no endpoint that mints a staff session from a token, so nothing an
 * organizer — or a staff member's own console sign-in — holds can become one.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * The handoff code from the console link, for the session's token.
     *
     * Unauthenticated by necessity: the tab it arrives in has no credential
     * of its own, and must not borrow one. The code is 48 random characters,
     * works once and for a minute, and the route is throttled besides.
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:128'],
        ]);

        try {
            ['session' => $session, 'token' => $token] = $this->impersonation->exchange($data['code'], $request->ip());
        } catch (ImpersonationRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        return response()->json(['token' => $token] + $this->present(
            $session,
            $session->organization,
            WhileImpersonating::permissionNames(),
        ));
    }

    /**
     * The session this token belongs to: what the console's banner shows and
     * the membership its screens work in.
     *
     * The permissions come from the same place every policy asks, rather than
     * from WhileImpersonating directly, so this answers what the server will
     * actually allow.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = $this->impersonation->forToken($user->currentAccessToken());

        if ($session === null) {
            return response()->json(['message' => 'This is not a staff session.'], 404);
        }

        $organization = $user->organizations->firstWhere('id', $session->organization_id);

        abort_if($organization === null, 401, 'This staff session has ended.');

        return response()->json($this->present($session, $organization, $user->permissionsIn($organization)));
    }

    /** End: the token stops working at once, and the trail records who ended it. */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = $this->impersonation->forToken($user->currentAccessToken());

        if ($session === null) {
            return response()->json(['message' => 'This is not a staff session.'], 404);
        }

        $this->impersonation->end($session, 'ended', $user);

        return response()->json(['message' => 'Staff session ended.']);
    }

    /**
     * Shaped as a console sign-in is, so the console's session store takes it
     * unchanged, with what the banner needs beside it.
     *
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function present(ImpersonationSession $session, Organization $organization, array $permissions): array
    {
        $staff = $session->staff;

        return [
            'user' => ['name' => $staff?->name ?? $session->staff_label, 'email' => $staff?->email],
            'abilities' => [TokenAbility::Organizer->value, Impersonation::ABILITY],
            'organizations' => [[
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $session->role,
                'permissions' => $permissions,
                'verified' => $organization->isVerified(),
            ]],
            'impersonation' => [
                'id' => $session->id,
                'organization' => ['id' => $organization->id, 'name' => $organization->name],
                'staff' => ['name' => $staff?->name ?? $session->staff_label],
                'reason' => $session->reason,
                'started_at' => $session->created_at,
                'expires_at' => $session->expires_at,
                // Named so the console can say what is out of reach, rather
                // than leave staff to find out one refusal at a time.
                'withheld' => array_map(fn ($p) => $p->value, WhileImpersonating::WITHHELD),
                // The payouts screen follows the staff member's own role, as
                // it does in the admin panel; see WhileImpersonating::PAYOUTS.
                'payouts' => WhileImpersonating::seesPayouts($staff?->platform_role),
            ],
        ];
    }
}
