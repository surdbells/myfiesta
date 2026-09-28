<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * The two things every organizer list asks the same way: a filter that can
 * take several values, and a sort.
 *
 * Written once so the console's table can speak to any list in one dialect —
 * `?status[]=paid&status[]=refunded&sort=total&dir=desc` — and so each list
 * decides only which columns it can be sorted by, never how a sort is read.
 */
final class Listing
{
    /**
     * The values of a filter that may be given once or several times.
     *
     * `status=paid`, `status[]=paid&status[]=refunded` and `status=paid,refunded`
     * all mean what they look like: a single select, a multi-select, and a link
     * somebody typed. Blank values are dropped. Anything outside `$allowed`
     * (when given) is refused against the filter's name, the way a validation
     * rule would, rather than silently matching nothing.
     *
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    public static function many(Request $request, string $key, ?array $allowed = null, int $max = 50): array
    {
        $raw = $request->query($key, $request->input($key));

        $values = collect(Arr::wrap($raw))
            ->flatMap(fn ($value) => is_string($value) ? explode(',', $value) : [$value])
            ->map(fn ($value) => is_scalar($value) ? trim((string) $value) : null)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->unique()
            ->values();

        if ($values->count() > $max) {
            throw ValidationException::withMessages([$key => "At most {$max} at once."]);
        }

        if ($allowed !== null && ($unknown = $values->diff($allowed))->isNotEmpty()) {
            throw ValidationException::withMessages([$key => 'Not one of these: '.$unknown->implode(', ').'.']);
        }

        return $values->all();
    }

    /**
     * The same, for ids: each must be a UUID.
     *
     * @return list<string>
     */
    public static function ids(Request $request, string $key, int $max = 500): array
    {
        $ids = self::many($request, $key, null, $max);

        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
                throw ValidationException::withMessages([$key => 'Each one must be an id.']);
            }
        }

        return $ids;
    }

    /**
     * Order a list by the column the reader asked for.
     *
     * `$columns` names what each sortable column is in SQL: a column, or an
     * expression when the thing shown is computed (`lower(buyer_name)`). Only
     * those keys are accepted — the SQL is never the caller's. After it come
     * `$tiebreak`, so rows with the same total do not trade places between
     * one page and the next, which is how a row is seen twice and another
     * never.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  array<string, string>  $columns  sort key => SQL expression
     * @param  array{0: string, 1: 'asc'|'desc'}  $default
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $tiebreak
     * @return TBuilder
     */
    public static function sort($query, Request $request, array $columns, array $default, array $tiebreak = [])
    {
        $data = validator($request->query(), [
            'sort' => ['nullable', 'string', 'in:'.implode(',', array_keys($columns))],
            'dir' => ['nullable', 'in:asc,desc'],
        ])->validate();

        $key = $data['sort'] ?? $default[0];
        $direction = $data['dir'] ?? (isset($data['sort']) ? 'asc' : $default[1]);

        // Nulls last whichever way it runs: an order with no payment date yet
        // is not the oldest or the newest, it is the one still to come.
        $query->orderByRaw($columns[$key].' '.$direction.' nulls last');

        foreach ($tiebreak as [$column, $way]) {
            $query->orderBy($column, $way);
        }

        return $query;
    }
}
