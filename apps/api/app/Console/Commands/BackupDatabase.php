<?php

namespace App\Console\Commands;

use App\Services\Backups\Backups;
use App\Services\Backups\BackupStore;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupDatabase extends Command
{
    protected $signature = 'backup:run
        {--no-prune : Keep every backup on the target, old ones included}';

    protected $description = 'Dump the database to the backup target, with a manifest of every table\'s rows, and thin out old backups';

    /**
     * Nightly, from the scheduler. A failure throws, which the scheduler
     * reports and Sentry's cron monitor notices; a night without a backup is
     * not something to find out about on the morning it is needed.
     */
    public function handle(Backups $backups, BackupStore $store): int
    {
        if (blank(config('operations.backup.encryption_key'))) {
            $this->warn('BACKUP_ENCRYPTION_KEY is empty, so this backup is stored as pg_dump wrote it. Anybody who can read the target can read every buyer\'s details.');
        }

        ['backup' => $backup, 'manifest' => $manifest, 'removed' => $removed] = $backups->run(prune: ! $this->option('no-prune'));

        $this->info(sprintf(
            'Stored %s in %s: %s, %d tables, %s rows.',
            $backup->name,
            $store->describe(),
            Number::fileSize($manifest['bytes'], precision: 1),
            count($manifest['tables']),
            number_format(array_sum($manifest['tables'])),
        ));

        foreach ($removed as $old) {
            $this->line("  removed {$old->name}");
        }

        return self::SUCCESS;
    }
}
