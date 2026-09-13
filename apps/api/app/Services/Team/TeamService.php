<?php

namespace App\Services\Team;

use App\Enums\Role;
use App\Mail\TeamInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Door\DoorPasses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Who is on an organization's team, and in what role.
 *
 * The rules that matter most are the ones that stop an organization locking
 * itself out: there is always at least one owner, so nobody can remove or
 * demote the last one — including themselves.
 */
class TeamService
{
    public const INVITATION_DAYS = 7;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly DoorPasses $doorPasses,
    ) {}

    /**
     * @return array{invitation: OrganizationInvitation, token: string}
     *
     * @throws TeamRefused
     */
    public function invite(Organization $organization, User $by, string $email, Role $role): array
    {
        $email = Str::lower(trim($email));

        $alreadyMember = $organization->members()->whereRaw('lower(users.email) = ?', [$email])->exists();

        if ($alreadyMember) {
            throw TeamRefused::because('That person is already on the team. Change their role instead.');
        }

        return DB::transaction(function () use ($organization, $by, $email, $role) {
            // Inviting again replaces the open invitation: the old link stops
            // working, so there is only ever one to worry about.
            OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $token = Str::random(48);

            $invitation = OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => OrganizationInvitation::hashToken($token),
                'invited_by' => $by->id,
                'expires_at' => now()->addDays(self::INVITATION_DAYS),
            ]);

            DB::afterCommit(fn () => Mail::to($email)->send(new TeamInvitationMail($invitation->fresh(['organization', 'inviter']), $token)));

            $this->auditor->record('team.invited', $organization, $by, $organization->id, [
                'email' => $email,
                'role' => $role->value,
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    /** @throws TeamRefused */
    public function accept(OrganizationInvitation $invitation, User $user): Organization
    {
        if ($invitation->state() !== 'open') {
            throw TeamRefused::because(match ($invitation->state()) {
                'accepted' => 'This invitation has already been used.',
                'revoked' => 'This invitation was withdrawn. Ask for a new one.',
                default => 'This invitation has expired. Ask for a new one.',
            });
        }

        // The address it was sent to, not whoever holds the link. A forwarded
        // invitation to finance is exactly the thing this stops.
        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw TeamRefused::because("This invitation was sent to {$invitation->email}. Sign in with that address to accept it.");
        }

        return DB::transaction(function () use ($invitation, $user) {
            $organization = $invitation->organization;

            if (! $organization->members()->whereKey($user->id)->exists()) {
                $organization->members()->attach($user->id, [
                    'id' => (string) Str::uuid(),
                    'role' => $invitation->role->value,
                    'invited_at' => $invitation->created_at,
                    'accepted_at' => now(),
                ]);
            }

            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->id]);

            $this->auditor->record('team.joined', $organization, $user, $organization->id, [
                'role' => $invitation->role->value,
            ]);

            return $organization;
        });
    }

    /** @throws TeamRefused */
    public function changeRole(Organization $organization, User $by, User $member, Role $role): void
    {
        $current = $member->roleIn($organization);

        if ($current === null) {
            throw TeamRefused::because('That person is not on this team.');
        }

        if ($current === Role::Owner && $role !== Role::Owner && $this->ownerCount($organization) === 1) {
            throw TeamRefused::because('Every organization needs an owner. Make somebody else an owner first.');
        }

        $organization->members()->updateExistingPivot($member->id, ['role' => $role->value]);

        $this->auditor->record('team.role_changed', $organization, $by, $organization->id, [
            'member' => $member->email,
            'from' => $current->value,
            'to' => $role->value,
        ]);
    }

    /** @throws TeamRefused */
    public function remove(Organization $organization, User $by, User $member): void
    {
        $current = $member->roleIn($organization);

        if ($current === null) {
            throw TeamRefused::because('That person is not on this team.');
        }

        if ($current === Role::Owner && $this->ownerCount($organization) === 1) {
            throw TeamRefused::because('Every organization needs an owner. Make somebody else an owner before removing this one.');
        }

        $organization->members()->detach($member->id);

        // Phones they put on a door stop with them. Left running, a removed
        // manager would still have scanners out in the world on their say-so.
        $ended = $this->doorPasses->endIssuedBy($member, $organization);

        $this->auditor->record('team.removed', $organization, $by, $organization->id, [
            'member' => $member->email,
            'role' => $current->value,
            'door_passes_ended' => $ended,
        ]);
    }

    public function revoke(OrganizationInvitation $invitation, User $by): void
    {
        $invitation->update(['revoked_at' => now()]);

        $this->auditor->record('team.invitation_revoked', $invitation->organization, $by, $invitation->organization_id, [
            'email' => $invitation->email,
        ]);
    }

    private function ownerCount(Organization $organization): int
    {
        return $organization->members()->wherePivot('role', Role::Owner->value)->count();
    }
}
