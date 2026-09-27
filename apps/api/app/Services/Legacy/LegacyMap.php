<?php

namespace App\Services\Legacy;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What became of each row from the old database.
 *
 * Held in memory as well as written, because the import asks the same
 * questions thousands of times — every one of 2,656 tickets looks up its
 * order, its type and its event — and a round trip each would make the run
 * three times longer for nothing.
 *
 * The memory has to agree with the table, and a rolled-back row is where they
 * would part company: the insert is undone, the cached answer is not, and
 * every ticket after it is written against an order that does not exist.
 * So entries recorded inside a unit of work are forgotten when it rolls back.
 */
class LegacyMap
{
    /** @var array<string, string|null> */
    private array $cache = [];

    /**
     * Keys recorded by the unit of work in progress, forgotten if it fails.
     *
     * @var list<string>|null
     */
    private ?array $pending = null;

    /**
     * Failures a row coming across has to clear: the ones still outstanding,
     * and any a person left behind, which are still tried.
     *
     * @var array<string, true>
     */
    private array $toClear = [];

    /**
     * Load an existing map, so a re-run picks up where it stopped.
     *
     * A failure for a row the map already has is cleared here. The row is
     * across; what failed was hearing so — a COMMIT that reached the server
     * and whose answer was lost with the connection. Nothing would ever try
     * that row again, so nothing else would ever clear it, and every run
     * after would end listing it and exit non-zero.
     */
    public function warm(): void
    {
        DB::table('legacy_map')
            ->select(['source_table', 'source_id', 'target_id'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $this->cache[$row->source_table.':'.$row->source_id] = $row->target_id;
            });

        $across = [];

        DB::table('legacy_import_failures')
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhereNotNull('left_behind_because'))
            ->select(['id', 'source_table', 'source_id'])
            ->orderBy('id')
            ->each(function (object $row) use (&$across): void {
                $key = $row->source_table.':'.$row->source_id;

                if (isset($this->cache[$key])) {
                    $across[] = $row->id;

                    return;
                }

                $this->toClear[$key] = true;
            });

        if ($across !== []) {
            $this->clear(DB::table('legacy_import_failures')->whereIn('id', $across));
        }
    }

    public function find(string $sourceTable, int|string|null $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        return $this->cache[$sourceTable.':'.$sourceId] ?? null;
    }

    /**
     * @param  array<string, string>  $inferred  anything guessed rather than read
     */
    public function record(
        string $sourceTable,
        int|string $sourceId,
        string $targetType,
        string $targetId,
        array $inferred = [],
    ): void {
        DB::table('legacy_map')->insert([
            'source_table' => $sourceTable,
            'source_id' => (string) $sourceId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'inferred' => $inferred === [] ? null : json_encode($inferred),
            'created_at' => now(),
        ]);

        $key = $sourceTable.':'.$sourceId;

        $this->cache[$key] = $targetId;

        if ($this->pending !== null) {
            $this->pending[] = $key;
        }
    }

    /**
     * One source row's whole unit of work, in one short transaction.
     *
     * The entity, its children, its ledger entries and its legacy_map row
     * commit together or not at all. Written separately, a run that died
     * between an order and its lines left an order with no basket that the map
     * called done, so the next run skipped it for good; with the map written
     * last instead, the next run would have made the order a second time.
     *
     * Short, and per row rather than per run: a transaction held across the
     * whole import would keep its locks for as long as the import takes and
     * lose everything to one bad row two hours in.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function atomically(Closure $work): mixed
    {
        $this->pending = [];

        try {
            return DB::transaction($work);
        } catch (Throwable $e) {
            foreach ($this->pending as $key) {
                unset($this->cache[$key]);
            }

            throw $e;
        } finally {
            $this->pending = null;
        }
    }

    /**
     * A row that did not come across, written down so the run can carry on.
     *
     * Upserted: the same row failing on three runs is one row with three
     * attempts, and its reason is the latest one — which is the one that
     * says what is still wrong.
     *
     * A row somebody chose to leave behind is still tried on every run, in
     * case its cause was fixed, and failing again does not put it back on
     * the list they took it off.
     */
    public function failed(string $sourceTable, int|string $sourceId, string $reason): void
    {
        $now = now();

        $existing = DB::table('legacy_import_failures')
            ->where('source_table', $sourceTable)
            ->where('source_id', (string) $sourceId)
            ->first(['id', 'left_behind_because']);

        if ($existing) {
            $changes = [
                'reason' => $reason,
                'attempts' => DB::raw('attempts + 1'),
                'last_failed_at' => $now,
            ];

            // Back on the list, unless a person took it off on purpose.
            if ($existing->left_behind_because === null) {
                $changes['resolved_at'] = null;
            }

            DB::table('legacy_import_failures')->where('id', $existing->id)->update($changes);
        } else {
            DB::table('legacy_import_failures')->insert([
                'source_table' => $sourceTable,
                'source_id' => (string) $sourceId,
                'reason' => $reason,
                'attempts' => 1,
                'first_failed_at' => $now,
                'last_failed_at' => $now,
            ]);
        }

        $this->toClear[$sourceTable.':'.$sourceId] = true;
    }

    /**
     * A row that failed before and has now come across.
     *
     * Called inside the row's own transaction, so the failure is cleared by
     * the same commit that brings the row across and by nothing else. One
     * that had been left behind is cleared the same way: it is across, and
     * the record says so rather than that somebody gave up on it.
     *
     * Checked against memory first. Nearly every row never failed, and asking
     * the database about each of them would be thousands of queries to learn
     * nothing.
     */
    public function resolved(string $sourceTable, int|string $sourceId): void
    {
        $key = $sourceTable.':'.$sourceId;

        if (! isset($this->toClear[$key])) {
            return;
        }

        $this->clear(
            DB::table('legacy_import_failures')
                ->where('source_table', $sourceTable)
                ->where('source_id', (string) $sourceId)
        );

        unset($this->toClear[$key]);
    }

    /**
     * Marks failures as across, including any that had been left behind.
     */
    private function clear(Builder $failures): void
    {
        $failures
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhereNotNull('left_behind_because'))
            ->update(['resolved_at' => now(), 'left_behind_because' => null]);
    }

    /**
     * A row that did not come across, accepted as staying behind.
     *
     * For the row whose fix is not to bring it: a duplicate account merged by
     * hand, a test sale somebody left in the live database. Without this the
     * only way to stop it being listed was to delete it from the loaded dump,
     * and then nothing read it again to clear it — the run went on failing
     * over a row that no longer existed.
     *
     * Only an outstanding failure can be left behind, and the reason is kept
     * with it. False when there is no such failure.
     *
     * Still tried on every run, in case its cause is fixed after all; if it
     * comes across, resolved() says so. Failing again leaves it behind.
     */
    public function leaveBehind(string $sourceTable, string $sourceId, string $because): bool
    {
        $updated = DB::table('legacy_import_failures')
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'left_behind_because' => $because]);

        return $updated > 0;
    }

    /**
     * Everything still not across, oldest first.
     *
     * @return list<object{source_table: string, source_id: string, reason: string, attempts: int}>
     */
    public function outstandingFailures(): array
    {
        return DB::table('legacy_import_failures')
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->get(['source_table', 'source_id', 'reason', 'attempts'])
            ->all();
    }

    /**
     * How much of the import had to be guessed.
     *
     * Reported at the end of a run. An event's end time and a timezone taken
     * from a currency are both things somebody should look at, and both are
     * invisible once the rows look like every other row.
     *
     * @return array<string, int>
     */
    public function inferenceSummary(): array
    {
        $summary = [];

        DB::table('legacy_map')
            ->whereNotNull('inferred')
            ->select('inferred')
            ->orderBy('id')
            ->each(function (object $row) use (&$summary): void {
                foreach (array_keys((array) json_decode($row->inferred, true)) as $key) {
                    $summary[$key] = ($summary[$key] ?? 0) + 1;
                }
            });

        return $summary;
    }
}
