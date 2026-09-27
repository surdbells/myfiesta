<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneExpired extends Command
{
    protected $signature = 'app:prune-expired';

    protected $description = 'Delete address changes and sign-ups whose links have run out';

    /**
     * Rows that were only ever waiting for somebody to open a link.
     *
     * Once the link has run out nothing can complete them, and each holds an
     * email address — a sign-up holds a name and a password hash as well — so
     * keeping them is keeping personal data for no reason. Sign-ups are also
     * cleared as new ones arrive; this catches the ones nobody followed.
     *
     * Only these two tables, by name. The ledger, the audit trail, orders,
     * tickets and scans are records, not waiting rooms, and nothing here
     * reaches them.
     */
    private const EXPIRING = ['email_changes', 'pending_registrations'];

    public function handle(): int
    {
        foreach (self::EXPIRING as $table) {
            if (! Schema::hasTable($table)) {
                $this->line("  {$table}: not in this database, skipped.");

                continue;
            }

            $removed = DB::table($table)->where('expires_at', '<', now())->delete();

            $this->line("  {$table}: removed {$removed} expired.");
        }

        return self::SUCCESS;
    }
}
