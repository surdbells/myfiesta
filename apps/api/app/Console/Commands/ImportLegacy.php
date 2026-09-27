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
 *
 * Each source row commits whole or not at all, and a row that fails does not
 * stop the run: it is listed at the end and kept in legacy_import_failures.
 * Fix the cause and run it again; only the rows that are missing are tried.
 * A row that is meant to stay behind is said so, with a reason:
 *
 *   php artisan legacy:import --leave-behind=user_accounts:32 --because="duplicate of 26"
 *
 * The money it imports is the old database's word for it. Check it against
 * Stripe with `legacy:reconcile` before anybody is paid out — docs/CUTOVER.md
 * has the order of operations.
 */
class ImportLegacy extends Command
{
    protected $signature = 'legacy:import
        {--posters : also move the poster blobs to object storage}
        {--posters-only : skip the rows and move only posters}
        {--limit= : stop after this many posters, for a look before committing}
        {--leave-behind=* : table:id of a row that did not come across and is to stay behind; imports nothing}
        {--because= : why, kept with each row left behind}';

    protected $description = 'Import the live myFiesta database into this one';

    public function handle(): int
    {
        if ($this->option('leave-behind') !== []) {
            return $this->leaveBehind();
        }

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

        return $this->reportFailures($map);
    }

    /**
     * The rows that did not come across, all of them, every time.
     *
     * Read from legacy_import_failures rather than from this run alone. A
     * poster-only run, or a re-run that skipped a row because its parent is
     * still missing, would otherwise finish green with rows outstanding.
     *
     * A non-zero exit when any are left, so a cutover script that runs this
     * and carries on does not carry on past it.
     */
    private function reportFailures(LegacyMap $map): int
    {
        $failures = $map->outstandingFailures();

        if ($failures === []) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error(number_format(count($failures)).' rows did not come across.');

        $this->table(
            ['source table', 'legacy id', 'attempts', 'reason'],
            collect($failures)->map(fn (object $f) => [
                $f->source_table, $f->source_id, $f->attempts, $f->reason,
            ])->all(),
        );

        $this->line('  Each was rolled back whole; nothing of it is half-written. Fix the cause');
        $this->line('  in the source and run this again: only rows that are missing are tried.');
        $this->line('  A row that is meant to stay behind: --leave-behind=<table>:<id> --because="<why>".');
        $this->line('  <fg=gray>Kept in legacy_import_failures until they come across.</>');

        return self::FAILURE;
    }

    /**
     * Rows a person has decided are not coming across.
     *
     * Nothing is imported: this is a decision about the list, made after
     * reading it. Each must be a failure that is outstanding now — a typo
     * leaves nothing behind and says so — and the reason is required, because
     * the cutover record is read by somebody who was not there.
     */
    private function leaveBehind(): int
    {
        $because = trim((string) $this->option('because'));

        if ($because === '') {
            $this->components->error('Say why with --because="…". It is kept with the row.');

            return self::FAILURE;
        }

        $map = new LegacyMap;
        $map->warm();

        $refused = false;

        foreach ($this->option('leave-behind') as $row) {
            [$table, $id] = array_pad(explode(':', (string) $row, 2), 2, '');

            if ($table === '' || $id === '' || ! $map->leaveBehind($table, $id, $because)) {
                $this->components->error("{$row} is not a row that did not come across. Nothing was changed for it.");
                $refused = true;

                continue;
            }

            $this->components->twoColumnDetail("{$table} {$id}", 'left behind');
        }

        $remaining = $this->reportFailures($map);

        if ($remaining === self::SUCCESS) {
            $this->components->info('Nothing else is outstanding.');
        }

        return $refused ? self::FAILURE : $remaining;
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
