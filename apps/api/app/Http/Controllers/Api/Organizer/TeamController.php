<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Team\TeamRefused;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The team screen: members, their roles, and open invitations.
 */
class TeamController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $members = $organization->members()
            ->orderByRaw("case organization_user.role when 'owner' then 0 when 'manager' then 1 when 'finance' then 2 when 'marketing' then 3 else 4 end")
            ->orderBy('users.name')
            ->get();

        $invitations = OrganizationInvitation::query()
            ->with('inviter:id,name')
            ->where('organization_id', $organization->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'members' => $members->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role instanceof Role ? $u->pivot->role->value : $u->pivot->role,
                'joined_at' => $u->pivot->accepted_at,
                'is_you' => $u->id === $request->user()->id,
            ])->values(),
            'invitations' => $invitations->map(fn (OrganizationInvitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->role->value,
                'invited_by' => $i->inviter?->name,
                'expires_at' => $i->expires_at,
                'expired' => $i->state() === 'expired',
            ])->values(),
            'roles' => collect(Role::cases())->map(fn (Role $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'description' => TeamInvitationMail::describe($r->value),
            ])->values(),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        try {
            $this->team->invite($organization, $request->user(), $data['email'], Role::from($data['role']));
        } catch (TeamRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return response()->json(['message' => "Invitation sent to {$data['email']}."], 201);
    }

    public function updateRole(Request $request, User $member): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);

        try {
            $this->team->changeRole($organization, $request->user(), $member->load('organizations'), Role::from($data['role']));
        } catch (TeamRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return response()->json(['message' => "{$member->name} is now ".Role::from($data['role'])->label().'.']);
    }

    public function remove(Request $request, User $member): JsonResponse
    {
        $organization = $this->organization($request);

        try {
            $this->team->remove($organization, $request->user(), $member->load('organizations'));
        } catch (TeamRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return response()->json(['message' => "{$member->name} has been removed from the team."]);
    }

    public function revoke(Request $request, OrganizationInvitation $invitation): JsonResponse
    {
        $organization = $this->organization($request);

        abort_unless($invitation->organization_id === $organization->id, 404);

        $this->team->revoke($invitation, $request->user());

        return response()->json(['message' => 'Invitation withdrawn. The link no longer works.']);
    }

    /**
     * The selected organization, for somebody allowed to manage its team.
     *
     * Refused outright for anybody else: the team screen lists every member's
     * email address, which is not something door staff need.
     */
    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations()->get();
        $asked = $request->header('X-Organization');

        $organization = $asked ? $memberships->firstWhere('id', $asked) : $memberships->first();

        abort_unless($organization !== null, 403, 'No organization.');
        abort_unless(
            $request->user()->hasPermissionIn($organization->id, Permission::TeamManage),
            403,
            'Only owners can manage the team.',
        );

        return $organization;
    }
}
