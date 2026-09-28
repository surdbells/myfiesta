<?php

namespace App\Services\Discovery;

use App\Models\Event;
use App\Models\EventImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The categories and cities a stranger can browse by, with how much is on in
 * each and a picture to show for it.
 *
 * Only what has something on. A city or a category that leads to an empty
 * page is worse than one not offered, and the count is what tells somebody
 * whether the tap is worth it.
 *
 * The picture is a real poster — the next night in that city or of that kind
 * that has one, a featured night first — never a stock photograph of a crowd
 * that was not at any of them. Where nothing has a poster the cover is null,
 * and the site draws its own.
 */
class Places
{
    /** How far back a city page still answers: a place with a recent past is still a place. */
    public const RECENT_DAYS = 90;

    /** @var array<string, list<string>> each place's spellings, by slug, once found */
    private array $spellings = [];

    /**
     * Categories with something coming up, most first.
     *
     * @return list<array{category: string, slug: string, events: int, cover_url: string|null}>
     */
    public function categories(int $limit = 20): array
    {
        // Counts, not events: read as plain rows rather than as models.
        $rows = $this->upcoming()
            ->whereNotNull('category')
            ->selectRaw('category, count(*) as events')
            ->groupBy('category')
            ->orderByDesc('events')
            ->orderBy('category')
            ->limit($limit)
            ->toBase()
            ->get();

        $covers = $this->covers('category', $rows->pluck('category')->all());

        return $rows->map(fn ($row) => [
            'category' => (string) $row->category,
            'slug' => self::slug((string) $row->category),
            'events' => (int) $row->events,
            'cover_url' => $covers[$row->category] ?? null,
        ])->values()->all();
    }

    /**
     * Cities with something coming up, most first.
     *
     * One place for each slug, because the slug is the page: "Montréal" and
     * "Montreal" are one card, spelled the way most of its nights spell it and
     * counting both. Grouped by the name as typed, they were two cards with a
     * count each, both opening the same page, and the front page counted the
     * one city twice.
     *
     * @return list<array{city: string, country: string, slug: string, events: int, cover_url: string|null}>
     */
    public function cities(int $limit = 12): array
    {
        $places = array_slice($this->bySlug($this->upcoming()), 0, $limit);
        $covers = $this->covers('city', array_values(array_unique(array_merge([], ...array_column($places, 'spellings')))));

        return array_map(fn (array $place) => [
            'city' => $place['city'],
            'country' => $place['country'],
            'slug' => $place['slug'],
            'events' => $place['events'],
            'cover_url' => self::coverOf($covers, $place['spellings']),
        ], $places);
    }

    /** How many places have something coming up, counted as the cards are: once each. */
    public function cityCount(): int
    {
        return count($this->bySlug($this->upcoming()));
    }

    /**
     * Narrow a query of events to one city, however each event spells it.
     *
     * By the slug, as the city page does, so the listing under a city page
     * holds every night its count promised: "Montreal" finds the nights filed
     * under "Montréal" too. A name that makes no slug is matched as typed,
     * ignoring case, as it always was.
     *
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    public function whereCity(Builder $events, string $name): Builder
    {
        $slug = self::slug($name);

        if ($slug === '') {
            return $events->whereRaw('lower(events.city) = ?', [mb_strtolower($name)]);
        }

        // Asked once for each place: the front page narrows every shelf to
        // the same city.
        $this->spellings[$slug] ??= Event::query()
            ->published()
            ->where('kind', 'ticketed')
            ->whereNotNull('city')
            ->distinct()
            ->pluck('city')
            ->filter(fn ($city) => self::slug((string) $city) === $slug)
            ->values()
            ->all();

        // None: a city nothing is on in finds nothing, not everything.
        return $events->whereIn('events.city', $this->spellings[$slug]);
    }

    /**
     * One category by its slug, or null when there is no such category.
     *
     * The fixed list the console offers, and anything older that events are
     * still filed under. Known with nothing on is still a page — it says so
     * and points at what is — rather than a 404 for a link somebody shared
     * last month.
     *
     * @return array{category: string, slug: string, events: int, cover_url: string|null}|null
     */
    public function category(string $slug): ?array
    {
        $known = collect(config('events.categories', []))
            ->merge(Event::query()->published()->where('kind', 'ticketed')->whereNotNull('category')->distinct()->pluck('category'))
            ->unique()
            ->first(fn ($name) => self::slug((string) $name) === $slug);

        if ($known === null) {
            return null;
        }

        $name = (string) $known;

        return [
            'category' => $name,
            'slug' => $slug,
            'events' => $this->upcoming()->where('category', $name)->count(),
            'cover_url' => $this->covers('category', [$name])[$name] ?? null,
        ];
    }

    /**
     * One city by its slug, or null when nothing has been on there lately.
     *
     * Spelled the way most of its events spell it, when two spellings share a
     * slug ("Montréal" and "Montreal").
     *
     * @return array{city: string, country: string, slug: string, events: int, cover_url: string|null}|null
     */
    public function city(string $slug): ?array
    {
        $spellings = Event::query()
            ->published()
            ->where('kind', 'ticketed')
            ->where('starts_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->selectRaw('city, country, count(*) as events')
            ->groupBy('city', 'country')
            ->orderByDesc('events')
            ->toBase()
            ->get()
            ->filter(fn ($row) => self::slug((string) $row->city) === $slug)
            ->values();

        if ($spellings->isEmpty()) {
            return null;
        }

        $names = $spellings->pluck('city')->unique()->values()->all();
        $name = (string) $spellings->first()->city;

        return [
            'city' => $name,
            'country' => (string) $spellings->first()->country,
            'slug' => $slug,
            'events' => $this->upcoming()->whereIn('city', $names)->count(),
            'cover_url' => self::coverOf($this->covers('city', $names), $names),
        ];
    }

    /**
     * Every city with events in this query, one entry for each slug, most
     * events first. The spelling kept is the one most of them use.
     *
     * @param  Builder<Event>  $events
     * @return list<array{slug: string, city: string, country: string, events: int, spellings: list<string>}>
     */
    private function bySlug(Builder $events): array
    {
        $rows = $events
            ->whereNotNull('city')
            ->selectRaw('city, country, count(*) as events')
            ->groupBy('city', 'country')
            ->orderByDesc('events')
            ->orderBy('city')
            ->toBase()
            ->get();

        $places = [];

        foreach ($rows as $row) {
            $city = (string) $row->city;
            // A name that makes no slug is its own place, as it was before.
            $key = self::slug($city) ?: mb_strtolower($city);

            $places[$key] ??= ['slug' => self::slug($city), 'city' => $city, 'country' => (string) $row->country, 'events' => 0, 'spellings' => []];
            $places[$key]['events'] += (int) $row->events;

            if (! in_array($city, $places[$key]['spellings'], true)) {
                $places[$key]['spellings'][] = $city;
            }
        }

        $places = array_values($places);
        usort($places, fn (array $a, array $b) => [$b['events'], $a['city']] <=> [$a['events'], $b['city']]);

        return $places;
    }

    /**
     * The first poster any of these spellings has.
     *
     * @param  array<string, string>  $covers
     * @param  list<string>  $spellings
     */
    private static function coverOf(array $covers, array $spellings): ?string
    {
        foreach ($spellings as $spelling) {
            if (isset($covers[$spelling])) {
                return $covers[$spelling];
            }
        }

        return null;
    }

    /**
     * A name as it sits in an address: "Food & drink" is food-and-drink,
     * "Montréal" is montreal. The site never slugs a name itself — it links
     * with the slug this sends — so the two cannot disagree.
     */
    public static function slug(string $name): string
    {
        return Str::slug($name, '-', 'en', ['&' => 'and', '@' => 'at']);
    }

    /**
     * A poster for each of these values of a column: the next night with one,
     * a featured night first.
     *
     * Two queries whatever the number of values: one DISTINCT ON to choose an
     * event per value, one for their banners.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function covers(string $column, array $values): array
    {
        if ($values === []) {
            return [];
        }

        /** @var Collection<int, Event> $chosen */
        $chosen = $this->upcoming()
            ->whereIn($column, $values)
            ->whereHas('banner')
            ->selectRaw("distinct on (events.{$column}) events.id, events.{$column}")
            ->orderBy($column)
            ->orderByDesc('is_featured')
            ->orderBy('starts_at')
            ->get();

        $banners = EventImage::query()
            ->whereIn('event_id', $chosen->pluck('id'))
            ->where('kind', 'banner')
            ->get()
            ->keyBy('event_id');

        $covers = [];

        foreach ($chosen as $event) {
            $banner = $banners->get($event->id);

            if ($banner !== null) {
                $covers[(string) $event->getAttribute($column)] = $banner->renditionUrl('display');
            }
        }

        return $covers;
    }

    /**
     * Public nights still to come — the same rule the listing starts from.
     *
     * kind = ticketed is not optional: an invitation event is published so its
     * guests can reach it by token, and a wedding counted under "Toronto" is a
     * wedding the front page has admitted exists.
     *
     * @return Builder<Event>
     */
    private function upcoming(): Builder
    {
        return Event::query()
            ->published()
            ->where('kind', 'ticketed')
            ->where('starts_at', '>=', now());
    }
}
