<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyMap;
use App\Services\Legacy\LegacyPosterImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The cutover.
 *
 *   php artisan legacy:import              rows only
 *   php artisan legacy:import --posters    the 288 MB of blobs as well
 *   php artisan legacy:import --posters-only --limit=20   a sample, to look at
 *
 * Safe to run more than once. Every row is checked against legacy_map first,
 * so a second run continues rather than duplicates — which is the property
 * that matters, because a quarter of a gigabyte over a network will not finish
 * first time.
 */
class ImportLegacy extends Command
{
    protected $signature = 'legacy:import
        {--posters : also move the poster blobs to object storage}
        {--posters-only : skip the rows and move only posters}
        {--limit= : stop after this many posters, for a look before committing}';

    protected $description = 'Import the live myFiesta database into this one';

    public function handle(): int
    {
        if (! $this->connected()) {
            return self::FAILURE;
        }

        $map = new LegacyMap;
        $map->warm();

        if (! $this->option('posters-only')) {
            $this->components->info('Importing rows.');

            $result = (new LegacyImporter($map))->run();

            $this->table(
                ['source table', 'rows read'],
                collect($result['counts'])->map(fn ($n, $t) => [$t, number_format($n)])->values(),
            );

            foreach ($result['notes'] as $note) {
                $this->components->warn($note);
            }
        }

        if ($this->option('posters') || $this->option('posters-only')) {
            $this->components->info('Moving posters out of the database.');

            $limit = $this->option('limit');

            $posters = (new LegacyPosterImporter($map))->run($limit ? (int) $limit : null);

            $this->components->twoColumnDetail('moved', number_format($posters['moved']));
            $this->components->twoColumnDetail('nothing to move', number_format($posters['empty']));
            $this->components->twoColumnDetail('failed', number_format($posters['failed']));
            $this->components->twoColumnDetail(
                'out of the database',
                number_format($posters['bytes'] / 1_048_576, 1).' MB',
            );
        }

        $this->reportInferences($map);

        return self::SUCCESS;
    }

    /**
     * What the import had to guess, said out loud.
     *
     * The rows it invented look exactly like the rows it read, which is the
     * problem: an event carrying a six-hour duration nobody chose is
     * indistinguishable from one an organizer set. Printing the counts at the
     * end is the only moment anybody is going to see them.
     */
    private function reportInferences(LegacyMap $map): void
    {
        $summary = $map->inferenceSummary();

        if ($summary === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('Inferred, not read from the source:');

        foreach ($summary as $what => $count) {
            $this->components->twoColumnDetail($what, number_format($count).' rows');
        }

        $this->newLine();
        $this->line('  <fg=gray>Every one is recorded in legacy_map.inferred.</>');
    }

    private function connected(): bool
    {
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->components->error('No connection to the legacy database.');
            $this->line('  Set LEGACY_DB_HOST, LEGACY_DB_DATABASE, LEGACY_DB_USERNAME and');
            $this->line('  LEGACY_DB_PASSWORD, and load the dump into MySQL first:');
            $this->newLine();
            $this->line('    mysql -u root -p sql_myfiesta_ca < sql_myfiesta_ca.sql');
            $this->newLine();
            $this->line('  <fg=gray>'.$e->getMessage().'</>');

            return false;
        }

        return true;
    }
}
