<?php

namespace App\Services\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * What became of each row from the old database.
 *
 * Held in memory as well as written, because the import asks the same
 * questions thousands of times — every one of 2,656 tickets looks up its
 * order, its type and its event — and a round trip each would make the run
 * three times longer for nothing.
 */
class LegacyMap
{
    /** @var array<string, string|null> */
    private array $cache = [];

    /**
     * Load an existing map, so a re-run picks up where it stopped.
     */
    public function warm(): void
    {
        DB::table('legacy_map')
            ->select(['source_table', 'source_id', 'target_id'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $this->cache[$row->source_table.':'.$row->source_id] = $row->target_id;
            });
    }

    public function find(string $sourceTable, int|string|null $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        return $this->cache[$sourceTable.':'.$sourceId] ?? null;
    }

    /**
     * @param  array<string, string>  $inferred anything guessed rather than read
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

        $this->cache[$sourceTable.':'.$sourceId] = $targetId;
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
