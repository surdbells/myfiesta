<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * One shape for a page of a list, on every organizer endpoint that has one.
 *
 * Before this, three endpoints paged and said so three different ways — one
 * with a next URL, one with a total and no page number, one with nothing at
 * all — and the console read none of them, so the events list stopped at 30,
 * an event's orders at 30 and its guests at 50 without a word. A list that
 * quietly ends is worse than a slow one: the order somebody needed to refund
 * simply was not there.
 */
final class Paging
{
    /**
     * The page size a caller asked for, held to a range.
     *
     * Clamped rather than refused: nothing is gained by failing a request for
     * 500 rows when 100 can be sent, and the pager says how many there are.
     */
    public static function perPage(Request $request, int $default, int $max = 100): int
    {
        $asked = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        return $asked === false ? $default : max(1, min($max, $asked));
    }

    /** @return array{total: int, per_page: int, current_page: int, last_page: int, next: string|null} */
    public static function meta(LengthAwarePaginator $page): array
    {
        return [
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'next' => $page->nextPageUrl(),
        ];
    }
}
