<?php

namespace App\Services\Staff;

use App\Enums\PlatformRole;
use App\Models\EmailChange;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who works here: granting, changing and removing a staff role.
 *
 * Until this existed, platform_role could only be set by editing the database,
 * which is both how a mistake gets made and how it goes unrecorded. Now the
 * admin's Staff screen and the staff:grant / staff:revoke commands both come
 * through here, so the rules are decided once:
 *
 *  - Only a current administrator may change anybody's access. The check is
 *    made against the database inside the transaction, not against the user
 *    object the caller happened to load, so an administrator demoted a moment
 *    ago cannot finish a change they started. The console passes no actor:
 *    somebody with a shell on the server is past every check this could make.
 *  - Nobody changes their own role or removes their own access. That is
 *    for another administrator to do, and to be seen doing.
 *  - The last administrator who can sign in stays one. Otherwise nobody could
 *    manage staff from the admin again, and the fix is the database edit this
 *    replaced.
 *  - Only an existing account with a verified address and a password can be
 *    given a role. The sign-in code goes to that address, so it must be one
 *    the person has proven they read — and the role lasts only as long as
 *    the address does (User::booted()).
 *
 * Every change is audited with the staff member who made it; changes from the
 * console are marked as such. Removing access also ends the person's admin
 * sessions and remember cookies at once rather than on their next click.
 *
 * Every administrator row is locked before anything is read, so two
 * administrators demoting each other at the same moment cannot both succeed
 * and leave nobody.
 */
class StaffAccess
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Give an existing account a staff role, or change the one it has.
     *
     * @throws StaffChangeRefused
     */
    public function grant(string $email, PlatformRole $role, ?User $actor): User
    {
        $email = strtolower(trim($email));

        return DB::transaction(function () use ($email, $role, $actor): User {
            $admins = $this->lockAdministrators();
            $this->assertMayManage($actor, $admins);

            // Compared case-blind, as sign-up does: an address typed with a
            // capital is still the same inbox.
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();

            if ($user === null && User::onlyTrashed()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw StaffChangeRefused::because("The account for {$email} is deactivated. Reactivate it first if they should have access.");
            }

            if ($user === null) {
                throw StaffChangeRefused::because("No account uses {$email}. They need to sign up to myFiesta and verify that address first.");
            }

            $this->assertNotThemselves($user, $actor);

            if ($user->email_verified_at === null) {
                throw StaffChangeRefused::because("{$email} has not been verified yet. Staff sign-in codes are sent to it, so the owner has to prove they read it first.");
            }

            if ($user->password === null) {
                throw StaffChangeRefused::because("{$email} has never set a password: the account exists only because tickets were bought with that address. They need to claim it first.");
            }

            // A move to a new address asked for before this would otherwise
            // complete afterwards and take the role with it (User::booted()).
            // Staff cannot ask for one; this is the one asked for before.
            EmailChange::where('user_id', $user->id)->delete();

            if ($user->platform_role !== null) {
                return $this->changeLocked($user, $role, $actor, $admins);
            }

            $user->forceFill(['platform_role' => $role])->save();

            $this->auditor->record('staff.granted', $user, $actor, metadata: [
                'role' => $role->value,
                'via' => $this->via($actor),
            ]);

            return $user;
        });
    }

    /**
     * Move a staff member to a different role.
     *
     * @throws StaffChangeRefused
     */
    public function changeRole(User $staff, PlatformRole $role, ?User $actor): User
    {
        return DB::transaction(function () use ($staff, $role, $actor): User {
            $admins = $this->lockAdministrators();
            $this->assertMayManage($actor, $admins);

            $user = $this->lockStaff($staff);

            if ($user->trashed()) {
                throw StaffChangeRefused::because("The account for {$user->email} is deactivated. Revoke its access, or reactivate it first if they should keep working here.");
            }

            return $this->changeLocked($user, $role, $actor, $admins);
        });
    }

    /**
     * Take somebody's staff role away and sign them out of the admin.
     *
     * Their account, tickets and organizations are untouched: this is about
     * working here, not about being a customer.
     *
     * A deactivated account can be revoked too. It cannot sign in while
     * deactivated, but the role is still on it, and reactivating the account
     * — as a customer's, say — would hand the admin back with it.
     *
     * @throws StaffChangeRefused
     */
    public function revoke(User $staff, ?User $actor): User
    {
        return DB::transaction(function () use ($staff, $actor): User {
            $admins = $this->lockAdministrators();
            $this->assertMayManage($actor, $admins);

            $user = $this->lockStaff($staff);

            $this->assertNotThemselves($user, $actor);
            $this->assertAnotherAdministratorRemains($user, $admins);

            $held = $user->platform_role;

            $user->forceFill(['platform_role' => null]);
            // A copied remember cookie must not outlast the job.
            $user->setRememberToken(Str::random(60));
            $user->save();

            $this->endSessions($user);

            $this->auditor->record('staff.revoked', $user, $actor, metadata: array_filter([
                'role' => $held?->value,
                'via' => $this->via($actor),
                'account_deactivated' => $user->trashed() ?: null,
            ], fn ($value) => $value !== null));

            return $user;
        });
    }

    private function changeLocked(User $user, PlatformRole $role, ?User $actor, Collection $admins): User
    {
        $this->assertNotThemselves($user, $actor);

        $held = $user->platform_role;

        if ($held === $role) {
            return $user;
        }

        $this->assertAnotherAdministratorRemains($user, $admins);

        $user->forceFill(['platform_role' => $role])->save();

        $this->auditor->record('staff.role_changed', $user, $actor, metadata: [
            'from' => $held?->value,
            'to' => $role->value,
            'via' => $this->via($actor),
        ]);

        return $user;
    }

    /**
     * Every administrator, locked, in a fixed order so two of these cannot
     * deadlock each other.
     *
     * @return Collection<int, User>
     */
    private function lockAdministrators(): Collection
    {
        return User::query()
            ->where('platform_role', PlatformRole::Admin->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'email_verified_at']);
    }

    /** Deactivated accounts included: see revoke(). */
    private function lockStaff(User $staff): User
    {
        $user = User::withTrashed()->whereKey($staff->getKey())->lockForUpdate()->first();

        if ($user === null || $user->platform_role === null) {
            throw StaffChangeRefused::because(($user ?? $staff)->email.' has no staff access to change. Grant it instead.');
        }

        return $user;
    }

    private function assertMayManage(?User $actor, Collection $admins): void
    {
        if ($actor === null) {
            return;
        }

        $isAdministrator = $admins->contains(
            fn (User $admin) => $admin->getKey() === $actor->getKey() && $admin->email_verified_at !== null,
        );

        if (! $isAdministrator) {
            throw StaffChangeRefused::because('Only an administrator can change who has staff access.');
        }
    }

    private function assertNotThemselves(User $user, ?User $actor): void
    {
        if ($actor !== null && $actor->getKey() === $user->getKey()) {
            throw StaffChangeRefused::because('You cannot change your own access. Ask another administrator to do it.');
        }
    }

    private function assertAnotherAdministratorRemains(User $user, Collection $admins): void
    {
        // A deactivated administrator is not one who can sign in, so taking
        // their role leaves exactly as many of those as there were.
        if ($user->platform_role !== PlatformRole::Admin || $user->trashed()) {
            return;
        }

        $others = $admins->filter(
            fn (User $admin) => $admin->getKey() !== $user->getKey() && $admin->email_verified_at !== null,
        );

        if ($others->isEmpty()) {
            throw StaffChangeRefused::because("{$user->email} is the last administrator who can sign in. Make somebody else an administrator first, or nobody could manage staff again.");
        }
    }

    /**
     * Sign the person out of every admin session now, not on their next click.
     *
     * Only the database driver can find a person's sessions. With any other,
     * AuthenticateStaff ends the session on its next request instead, and the
     * cycled remember token means no browser can sign back in.
     */
    private function endSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    private function via(?User $actor): string
    {
        return $actor === null ? 'console' : 'admin';
    }
}
