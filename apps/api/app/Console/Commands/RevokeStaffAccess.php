<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffChangeRefused;
use Illuminate\Console\Command;

/**
 * Taking somebody's staff role away from the server.
 *
 * Signs them out of the admin at once. Refuses to remove the last
 * administrator who can sign in, as the admin's Staff screen does. Works on a
 * deactivated account as well. Audited as coming from the console.
 */
class RevokeStaffAccess extends Command
{
    protected $signature = 'staff:revoke {email : The address the person signs in with}';

    protected $description = 'Remove somebody\'s staff role and sign them out of the admin panel';

    public function handle(StaffAccess $staff): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        // Deactivated accounts too. One that still holds a role would get the
        // admin back the day it is reactivated, and this is how it is taken off.
        $user = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            $this->error("No account uses {$email}.");

            return self::FAILURE;
        }

        // Asked twice, answered the same way: nothing to do is not an error.
        if ($user->platform_role === null) {
            $this->info("{$email} has no staff access. Nothing changed.");

            return self::SUCCESS;
        }

        $held = $user->platform_role;

        try {
            $staff->revoke($user, actor: null);
        } catch (StaffChangeRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info($user->trashed()
            ? "{$email} no longer has the {$held->label()} role. The account is deactivated; reactivating it will not give the role back."
            : "{$email} no longer has the {$held->label()} role, and has been signed out of the admin.");

        return self::SUCCESS;
    }
}
