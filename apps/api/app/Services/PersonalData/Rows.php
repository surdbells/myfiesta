<?php

namespace App\Services\PersonalData;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The rows of one table in config/personal_data.php that are about one person.
 *
 * One place for the export, the erasure and the "do we know you?" check to
 * find them, so the three can never disagree about whose a row is.
 *
 * Most tables hold the person themselves: an address or an account id in a
 * column of their own. Some only hold keys to things that do. An answer given
 * at checkout knows its order, and an answer about one person on it knows
 * their ticket too, so it is found `via` the orders placed under the address
 * and the tickets held under it — and has to be found before those lose the
 * address, or it can never be found again (Eraser).
 */
class Rows
{
    /** @param  array<string, mixed>  $spec  one entry of the map */
    public static function of(string $table, array $spec, string|int $key): Builder
    {
        $query = DB::table($table);

        $parents = self::parents($spec);

        if ($parents !== []) {
            // Each parent's rows for this person, and this table's rows that
            // point at any of them: order_answers.order_id in the ids of the
            // orders placed under the address, or order_answers.ticket_id in
            // the ids of the tickets held under it.
            return $query->where(function (Builder $query) use ($parents, $spec, $key) {
                foreach ($parents as $via) {
                    $query->orWhereIn(
                        $via['through'] ?? $spec['key'],
                        self::matching(DB::table($via['table']), $via['key'], $key)->select($via['column'] ?? 'id'),
                    );
                }
            });
        }

        return self::matching($query, $spec['key'], $key);
    }

    /**
     * The tables an entry is reached through: none, one, or a list of them.
     * Each says which of its columns holds the person (`key`), which it is
     * pointed at by (`column`, the id), and which column of this table does
     * the pointing (`through`, the entry's own key unless it says).
     *
     * @param  array<string, mixed>  $spec
     * @return list<array{table: string, key: string, column?: string, through?: string}>
     */
    public static function parents(array $spec): array
    {
        $via = $spec['via'] ?? null;

        if (! is_array($via)) {
            return [];
        }

        return isset($via['table']) ? [$via] : array_values($via);
    }

    /**
     * Only the rows an erasure clears, where an entry says only some are.
     *
     * An answer somebody typed can say anything about them; one they picked
     * from the organizer's own list says nothing once its order no longer
     * names them, and it is in the counts the organizer caters from.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function erasable(string $table, array $spec, string|int $key): Builder
    {
        $query = self::of($table, $spec, $key);

        foreach ($spec['erase_where'] ?? [] as $column => $through) {
            $query->whereIn(
                $column,
                DB::table($through['table'])->select('id')->whereIn($through['column'], $through['in']),
            );
        }

        return $query;
    }

    private static function matching(Builder $query, string $column, string|int $key): Builder
    {
        // Addresses are stored as they were typed; matched as they are meant.
        return is_string($key) && str_contains($key, '@')
            ? $query->whereRaw("lower({$column}) = ?", [$key])
            : $query->where($column, $key);
    }
}
