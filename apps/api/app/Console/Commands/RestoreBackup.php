<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupStore;
use App\Services\Backups\Restorer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The restore drill, and the real thing: see docs/OPERATIONS.md.
 *
 *   php artisan backup:restore --into=myfiesta_restore --create
 *
 * Into a new database, always. It never switches the application over; that
 * is a change to DB_DATABASE and a restart, made by a person who has read the
 * counts this prints.
 */
class RestoreBackup extends Command
{
    protected $signature = 'backup:restore
        {backup? : The backup\'s name, from backup:list. The newest when left out}
        {--into= : A new database to restore into. Never the one the application uses}
        {--create : Create that database first, on the same server}
        {--drop-after : For a drill: drop the database again at the end, if this run created it}
        {--jobs=4 : Tables loaded at once}';

    protected $description = 'Restore a backup into a new database and check every table\'s rows against its manifest';

    public function handle(BackupStore $store, Restorer $restorer): int
    {
        $into = (string) $this->option('into');

        if ($into === '') {
            $this->error('Say which new database to restore into: --into=myfiesta_restore (with --create to make it).');

            return self::FAILURE;
        }

        $name = $this->argument('backup');
        $backup = $name ? $store->find((string) $name) : $store->latest();

        if ($backup === null) {
            $this->error($name ? "There is no backup called {$name} in ".$store->describe().'.' : 'There are no backups in '.$store->describe().'.');

            return self::FAILURE;
        }

        if ($this->option('drop-after') && ! $this->option('create')) {
            $this->error('--drop-after only drops a database this run creates. Add --create.');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $result = $restorer->restore(
                $backup,
                $into,
                (bool) $this->option('create'),
                (int) $this->option('jobs'),
                fn (string $line) => $this->line($line),
            );

            $seconds = (int) round(microtime(true) - $started);
            $this->line('Fetched, loaded and counted in '.($seconds === 1 ? '1 second' : "{$seconds} seconds").'.');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // Only what this run made: a database that was already there,
            // and made CREATE fail, belongs to somebody.
            if ($this->option('drop-after') && $restorer->created === $into) {
                $restorer->drop($into);
                $this->line("Dropped {$into} again.");
            }
        }

        if ($result['mismatches'] !== []) {
            $this->error("Restored into {$into}, and these tables do not hold what the backup says they should:");

            $this->table(['Table', 'In the backup', 'Restored'], collect($result['mismatches'])
                ->map(fn (array $counts, string $table) => [$table, $counts['expected'], $counts['found'] ?? 'missing'])
                ->values()
                ->all());

            return self::FAILURE;
        }

        $this->info("Restored {$backup->name} into {$into}: all {$result['tables']} tables hold exactly the rows the backup recorded.");
        $this->line($this->option('drop-after')
            ? 'It was a drill, and the database has been dropped again. Write down the time above.'
            : 'Nothing has been switched over. To use it, follow "Switching over" in docs/OPERATIONS.md.');

        return self::SUCCESS;
    }
}
