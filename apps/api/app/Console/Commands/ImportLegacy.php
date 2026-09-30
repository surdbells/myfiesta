<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyImporter;
use App\Services\Legacy\LegacyMap;
use App\Services\Legacy\LegacyPosterImporter;
use App\Services\Legacy\LegacyRules;
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
 *
 * During a parallel run it is run again and again while the old app still
 * sells, and each run brings only the rows that are new. What it cannot do is
 * change a row it already brought, so it says which of those the old app has
 * changed or deleted since, and a person settles them (docs/CUTOVER.md):
 *
 *   php artisan legacy:import --dry-run    what a run would do, writing nothing
 *   php artisan legacy:import --frozen     the last run, once the old app has stopped
 *
 * A checkout the old app opened in the last two days is left for a later run,
 * because it may still be paid there; --frozen brings it as it stands, since a
 * frozen app will never find out.
 *
 * One run at a time, however many shells and containers there are.
 */
class ImportLegacy extends Command
{
    protected $signature = 'legacy:import
        {--posters : also move the poster blobs to object storage}
        {--posters-only : skip the rows and move only posters}
        {--limit= : stop after this many posters, for a look before committing}
        {--dry-run : say what a run would bring, retry and pass over, and write nothing}
        {--frozen : the old app has stopped taking orders; bring its open checkouts now instead of leaving them for a later run}
        {--leave-behind=* : table:id of a row that did not come across and is to stay behind; imports nothing}
        {--because= : why, kept with each row left behind}';

    protected $description = 'Import the live myFiesta database into this one';

    /**
     * The name of the lock one run holds (pg_try_advisory_lock).
     */
    private const LOCK = 'myfiesta:legacy:import';

    /**
     * The columns of the summary, in order, and what each is called.
     */
    private const OUTCOMES = [
        'across' => 'already here',
        'changed' => 'changed since',
        'new' => 'new',
        'retried' => 'tried again',
        'failed' => 'failed',
        'waiting' => 'waiting',
        'deferred' => 'left for later',
        'gone' => 'gone',
    ];

    /**
     * One run at a time.
     *
     * The map's unique key already stops a row being brought twice: of two
     * runs bringing the same order, the second's map row cannot commit and the
     * whole order goes back with it. What two runs do instead is report each
     * other's rows as failures, trip over each other writing those, approve
     * the same event twice, and give a dry run numbers that were never true.
     *
     * A lock in the database rather than in the cache, because the database
     * lets go of it when the process's connection closes, however the process
     * ended. A cache lock left by a run that was killed — a container
     * recreated, a shell closed mid-import — would refuse every run after it
     * until it expired, and on the day of a cutover that is the hour nobody
     * has.
     */
    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('leave-behind') !== []) {
            $this->components->error('--leave-behind changes the record, so it has no dry run. Run it on its own.');

            return self::FAILURE;
        }

        if (! $this->lock()) {
            $this->components->error('Another legacy:import is running against this database. Nothing was done.');
            $this->line('  Wait for it to finish (docker ps, or ps aux | grep legacy:import), then run this again.');

            return self::FAILURE;
        }

        try {
            return $this->option('leave-behind') !== [] ? $this->leaveBehind() : $this->import();
        } finally {
            $this->unlock();
        }
    }

    private function import(): int
    {
        if (! $this->connected()) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $map = new LegacyMap;
        $map->warm(write: ! $dryRun);

        if ($dryRun) {
            $this->components->info('Dry run: nothing will be written, here or in the old database.');
        }

        if (! $this->option('posters-only')) {
            $this->components->info($dryRun ? 'Reading rows.' : 'Importing rows.');

            $result = (new LegacyImporter($map, dryRun: $dryRun, frozen: (bool) $this->option('frozen')))->run();

            $this->reportPlan($result['plan'], $result['counts'], $dryRun);

            foreach ($result['notes'] as $note) {
                $this->components->warn($note);
            }

            $this->reportDeferred($result['deferred']);
            $this->reportChanges($result['changed'], $result['orders'], $result['gone']);
        }

        if ($dryRun) {
            if ($this->option('posters') || $this->option('posters-only')) {
                $this->components->twoColumnDetail(
                    'events whose poster is still to move',
                    number_format((new LegacyPosterImporter($map))->toMove()),
                );
            }

            $this->newLine();
            $this->line('  <fg=gray>A dry run cannot say whether a row would fail: only writing it tells. Nothing was written.</>');

            return self::SUCCESS;
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
     * What became of each source table's rows, one line per table.
     *
     * @param  array<string, array<string, int>>  $plan
     * @param  array<string, int>  $counts
     */
    private function reportPlan(array $plan, array $counts, bool $dryRun): void
    {
        $outcomes = self::OUTCOMES;

        if ($dryRun) {
            $outcomes['new'] = 'would come';
            $outcomes['retried'] = 'would retry';
            unset($outcomes['failed']);
        }

        $this->table(
            ['source table', 'read', ...array_values($outcomes)],
            collect($plan)->map(fn (array $row, string $table) => [
                $table,
                number_format($counts[$table] ?? 0),
                ...array_map(fn (string $what) => number_format($row[$what] ?? 0), array_keys($outcomes)),
            ])->values()->all(),
        );

        // Made from the rows above rather than read: an organization per
        // organizer, a venue per room, a logo per brand that had one.
        foreach (['organizations', 'venues', 'logos'] as $made) {
            if (isset($counts[$made])) {
                $this->components->twoColumnDetail("{$made} made", number_format($counts[$made]));
            }
        }
    }

    /**
     * @param  list<string>  $deferred
     */
    private function reportDeferred(array $deferred): void
    {
        if ($deferred === []) {
            return;
        }

        $this->newLine();
        $this->components->warn(
            'Checkouts from the last '.LegacyRules::OPEN_CHECKOUT_HOURS
            .' hours were left for a later run: they may still be paid in the old app.'
        );
        $this->components->twoColumnDetail('sales left for later', $this->some($deferred));
        $this->line('  A run once they are older brings them as the old app then says (docs/CUTOVER.md).');
        $this->line('  Once the old app has stopped taking orders it never will: run with --frozen to bring them now.');
    }

    /**
     * Rows that came across on an earlier run and that the old app has changed
     * or deleted since. None of them was changed here; each is for a person.
     *
     * Ids and statuses only. The rows hold names, emails and ticket codes, and
     * this output ends up in a log.
     *
     * @param  array<string, list<string>>  $changed
     * @param  list<array{legacy_id: string, reference: string, here: string, there: string, advice: string}>  $orders
     * @param  array<string, list<string>>  $gone
     */
    private function reportChanges(array $changed, array $orders, array $gone): void
    {
        if ($changed === [] && $gone === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('Rows changed in the old app since they came across.');
        $this->line('  Nothing here was changed: each is for a person to settle.');

        foreach ($changed as $table => $ids) {
            $this->components->twoColumnDetail("{$table} changed", $this->some($ids));
        }

        foreach ($gone as $table => $ids) {
            $this->components->twoColumnDetail("{$table} deleted in the old app", $this->some($ids));
        }

        if ($orders !== []) {
            $this->table(
                ['legacy sale', 'order', 'status here', 'old app now', 'what to do'],
                array_map(fn (array $o) => [$o['legacy_id'], $o['reference'], $o['here'], $o['there'], $o['advice']], $orders),
            );
        }

        $this->line('  Listed on every run from now on, settled or not. What to do with each:');
        $this->line('  docs/CUTOVER.md, "Rows that changed after they came across".');
    }

    /**
     * A list of ids, the first twenty of them.
     *
     * @param  list<string>  $ids
     */
    private function some(array $ids): string
    {
        $shown = implode(', ', array_slice($ids, 0, 20));

        return count($ids) > 20 ? $shown.' and '.number_format(count($ids) - 20).' more' : $shown;
    }

    private function lock(): bool
    {
        return (bool) DB::selectOne('select pg_try_advisory_lock(hashtext(?)) as locked', [self::LOCK])->locked;
    }

    /**
     * Let go of the lock. It goes anyway when the connection closes, so a
     * failure here only ever delays the next run until this process exits.
     */
    private function unlock(): void
    {
        try {
            DB::selectOne('select pg_advisory_unlock(hashtext(?)) as unlocked', [self::LOCK]);
        } catch (\Throwable) {
            // The connection is already gone, and the lock with it.
        }
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
