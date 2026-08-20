<?php

namespace App\Services\Discovery;

use App\Models\Event;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finding events.
 *
 * The previous platform had no search at all — no endpoint, no filters, no
 * sort. Its feeds returned every published event in one unpaginated response
 * and left the client to cope.
 *
 * This is Postgres full-text over a generated tsvector column, which is a large
 * part of why Postgres was chosen: search, ranking, and prefix matching without
 * a second service to run, index, and keep in sync.
 */
class EventSearch
{
    public function query(EventFilters $filters): CursorPaginator
    {
        $query = Event::query()
            ->published()
            // Invitation events are published so their invited guests can reach
            // them, not so strangers can. A wedding must never surface in a
            // public feed, and defaulting to open here would make that a
            // one-line mistake away for every future caller.
            ->where('kind', 'ticketed')
            // banner eager-loaded: a listing page renders one per card, and
            // lazily loading it turns one query into thirty.
            ->with(['organization:id,name,slug,logo_path', 'venue:id,name,city', 'banner']);

        $this->applyText($query, $filters->text);
        $this->applyPlace($query, $filters);
        $this->applyDates($query, $filters);
        $this->applyPrice($query, $filters);

        if ($filters->category !== null) {
            $query->where('category', $filters->category);
        }

        $this->applyOrdering($query, $filters);

        // Cursor rather than offset. Deep offset pagination degrades on large
        // tables, and an event publishing mid-scroll shifts every later page.
        return $query->orderBy('id')->cursorPaginate($filters->perPage);
    }

    private function applyText(Builder $query, ?string $text): void
    {
        if (blank($text)) {
            return;
        }

        // websearch_to_tsquery understands what people actually type — quoted
        // phrases, OR, leading minus — and does not throw on punctuation the
        // way to_tsquery does. A search box that 500s on an apostrophe is worse
        // than no search box.
        $query->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$text]);
    }

    private function applyPlace(Builder $query, EventFilters $filters): void
    {
        if ($filters->country !== null) {
            $query->where('country', strtoupper($filters->country));
        }

        if ($filters->city !== null) {
            $query->whereRaw('lower(city) = ?', [mb_strtolower($filters->city)]);
        }
    }

    private function applyDates(Builder $query, EventFilters $filters): void
    {
        // Past events are excluded unless asked for. Someone browsing wants
        // something to go to.
        $query->where('starts_at', '>=', $filters->from ?? now());

        if ($filters->to !== null) {
            $query->where('starts_at', '<=', $filters->to);
        }
    }

    /**
     * Price bands are matched against the cheapest way in.
     *
     * "Under $50" means there is some ticket under $50, not that every tier is.
     * Matching on the most expensive tier would hide affordable events behind
     * their VIP option.
     */
    private function applyPrice(Builder $query, EventFilters $filters): void
    {
        if ($filters->freeOnly) {
            $query->whereHas('ticketTypes', fn (Builder $q) => $q
                ->where('status', 'on_sale')
                ->where('price_amount', 0));

            return;
        }

        if ($filters->maxPrice !== null) {
            $query->whereHas('ticketTypes', fn (Builder $q) => $q
                ->where('status', 'on_sale')
                ->where('price_amount', '<=', $filters->maxPrice));
        }
    }

    private function applyOrdering(Builder $query, EventFilters $filters): void
    {
        // Relevance needs something to be relevant to. Asking for it without a
        // query would otherwise produce an arbitrary order wearing a
        // meaningful-sounding name, so it falls through to soonest-first.
        if ($filters->sort === 'relevance' && filled($filters->text)) {
            $query->orderByRaw(
                "ts_rank(search_vector, websearch_to_tsquery('simple', ?)) DESC",
                [$filters->text],
            );

            return;
        }

        match ($filters->sort) {
            'newest' => $query->orderByDesc('published_at'),
            default => $query->orderBy('starts_at'),
        };
    }
}
