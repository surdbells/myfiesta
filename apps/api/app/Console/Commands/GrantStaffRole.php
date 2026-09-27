<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffChangeRefused;
use Illuminate\Console\Command;

/**
 * Giving somebody a staff role from the server.
 *
 * For the first administrator, who has nobody to grant it to them in the
 * admin, and for the day every administrator has lost their inbox. Everything
 * else is better done from the admin's Staff screen, where it is attributed to
 * the person who did it; changes made here are audited as coming from the
 * console.
 *
 * Also changes the role of somebody who already has one.
 */
class GrantStaffRole extends Command
{
    protected $signature = 'staff:grant
        {email : The address the person signs in with}
        {role : admin, finance or support}';

    protected $description = 'Give an existing, verified account a staff role in the admin panel';

    public function handle(StaffAccess $staff): int
    {
        $role = PlatformRole::tryFrom(strtolower(trim((string) $this->argument('role'))));

        if ($role === null) {
            $this->error('There is no role called "'.$this->argument('role').'". Choose one of: '
                .implode(', ', array_map(fn (PlatformRole $r) => $r->value, PlatformRole::cases())).'.');

            return self::INVALID;
        }

        try {
            $user = $staff->grant((string) $this->argument('email'), $role, actor: null);
        } catch (StaffChangeRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("{$user->email} now has the {$role->label()} role.");
        $this->line('They sign in at '.url('/admin').' with their usual password and a code emailed to that address.');

        return self::SUCCESS;
    }
}
