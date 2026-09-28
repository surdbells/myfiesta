<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventSummaryResource;
use App\Models\Event;
use App\Services\Discovery\Availability;
use App\Services\Discovery\EventWindows;
use App\Services\Discovery\Places;
use App\Services\Settings\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Everything a home page needs, in one request.
 *
 * The public site had no home page — the root was the same flat search list as
 * /events, so somebody arriving without a link had nothing to look at and
 * nothing to browse by. The previous platform had six screens for this: home,
 * featured, upcoming, category, location and listing.
 *
 * One endpoint rather than ten, because this is the first screen a stranger
 * sees and ten round trips on a phone is ten chances to look broken. Every
 * section is small and bounded; "see all" on each is the listing with the
 * matching filter (when=today, availability=almost_sold_out, …), which pages.
 *
 * Kept for a minute (config/discovery.php). It is the same answer for every
 * stranger, and a dozen queries each time somebody opens the site is the
 * wrong place to spend the database. The event page and the ticket page are
 * never cached — those are where a badge has to agree with checkout.
 */
class DiscoverController extends Controller
{
    /** Small enough to render above the fold, large enough to look alive. */
    private const FEATURED = 6;

    /** Each shelf: enough to scroll, few enough to be a choice. */
    private const SHELF = 12;

    /** How long a sold-out night stays on the "sold out" shelf after it happened. */
    private const SOLD_OUT_DAYS = 30;

    /** How far back "recently" reaches. */
    private const PAST_DAYS = 90;

    public function __construct(
        private readonly Availability $availability,
        private readonly Places $places,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $city = mb_substr(trim((string) $request->query('city', '')), 0, 80);

        $body = Cache::remember(
            'discover:v3:'.md5(mb_strtolower($city)),
            $this->seconds(),
            fn () => self::plain($this->build($request, $city !== '' ? $city : null)),
        );

        return response()->json($body);
    }

    /**
     * The answer as the JSON it becomes, before it is kept.
     *
     * A card's dates are Carbon objects until they are encoded, and the cache
     * unserializes no classes (config/cache.php): kept as they were, every
     * date came back out as an incomplete object and the site read "Invalid
     * time value" on every card for the rest of the minute.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function plain(array $body): array
    {
        return json_decode(json_encode($body, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The categories and cities alone, for the listing's filters and the
     * search's city picker — the front page's two lists without its shelves.
     */
    public function facets(): JsonResponse
    {
        $body = Cache::remember('discover:v2:facets', $this->seconds(), fn () => [
            'categories' => $this->places->categories(),
            'cities' => $this->places->cities(50),
        ]);

        return response()->json($body);
    }

    /** A category's own page: its name, what is on in it, and its picture. */
    public function category(string $slug): JsonResponse
    {
        $category = Cache::remember('discover:v2:category:'.md5($slug), $this->seconds(), fn () => $this->places->category($slug));

        if ($category === null) {
            throw new NotFoundHttpException('No such category.');
        }

        return response()->json(['data' => $category]);
    }

    /** A city's own page, when anything has been on there lately. */
    public function city(string $slug): JsonResponse
    {
        $city = Cache::remember('discover:v2:city:'.md5($slug), $this->seconds(), fn () => $this->places->city($slug));

        if ($city === null) {
            throw new NotFoundHttpException('No such city.');
        }

        return response()->json(['data' => $city]);
    }

    /** @return array<string, mixed> */
    private function build(Request $request, ?string $city): array
    {
        $now = now();
        $cards = fn (Collection $events) => EventSummaryResource::collection($events)->resolve($request);

        return [
            'featured' => $cards($this->featured($city)),
            'upcoming' => $cards($this->base($city)->where('starts_at', '>', $now)->orderBy('starts_at')->limit(self::SHELF)->get()),

            // In each event's own zone, and still on: the festival that opened
            // at noon is "today" at six (EventWindows).
            'today' => $cards($this->window($city, 'today')),
            'weekend' => $cards($this->window($city, 'weekend')),
            // Everything else still to come, soonest first — upcoming without
            // what today and this weekend already show.
            'later' => $cards($this->window($city, 'later')),

            // Chosen in the query rather than over a page of results, so the
            // shelf fills from every night on sale and not from the first
            // dozen of them.
            'almost_sold_out' => $cards($this->availability
                ->whereState(EventWindows::notOver($this->base($city), $now), [Availability::ALMOST_SOLD_OUT])
                ->orderBy('starts_at')
                ->limit(self::SHELF)
                ->get()),

            'sold_out' => $cards($this->soldOut($city)),

            // Recently ended, most recent first: last month's gallery is what
            // sells next month's night to whoever missed it.
            'past' => $cards(tap($this->base($city), fn (Builder $query) => EventWindows::apply($query, 'past', $now))
                ->where('starts_at', '>=', $now->copy()->subDays(self::PAST_DAYS))
                ->orderByDesc('starts_at')
                ->limit(self::SHELF)
                ->get()),

            'cities' => $this->places->cities(),
            'categories' => $this->places->categories(),

            // Counted, not claimed: the only figures the front page states are
            // ones the database can answer for.
            'totals' => [
                'upcoming' => $this->upcoming($city)->count(),
                // As the city cards count them: "Montréal" and "Montreal" are
                // one city.
                'cities' => $this->places->cityCount(),
            ],

            // What a buyer pays on top, in each currency, for the organizer
            // pitch — said from the setting that charges it, so the page
            // cannot promise one rate while checkout adds another.
            'fees' => [
                'service_charge' => collect(PlatformSettings::CURRENCIES)
                    ->mapWithKeys(fn (string $currency) => [$currency => $this->percent(app(PlatformSettings::class)->serviceChargeBps($currency))])
                    ->all(),
            ],
        ];
    }

    /**
     * What the platform is putting its weight behind.
     *
     * Falls back to whatever is next when nothing is flagged. An empty hero on
     * the front page reads as a dead site, and a new market has no featured
     * events by definition.
     *
     * @return Collection<int, Event>
     */
    private function featured(?string $city): Collection
    {
        $featured = $this->base($city)
            ->where('starts_at', '>', now())
            ->where('is_featured', true)
            ->orderBy('starts_at')
            ->limit(self::FEATURED)
            ->get();

        return $featured->isNotEmpty()
            ? $featured
            : $this->base($city)->where('starts_at', '>', now())->orderBy('starts_at')->limit(self::FEATURED)->get();
    }

    /** @return Collection<int, Event> */
    private function window(?string $city, string $window): Collection
    {
        $query = $this->base($city);
        EventWindows::apply($query, $window, now());

        return $query->orderBy('starts_at')->limit(self::SHELF)->get();
    }

    /**
     * Sold out: what is still to come first — each of those takes names for
     * returned tickets — then what sold out and has since happened, the proof
     * that nights here do.
     *
     * Only nights whose every ticket went (Availability). One whose sales
     * simply stopped, or whose only tiers are a presale nobody has the code
     * for, is closed, not sold out, and proves nothing.
     *
     * @return Collection<int, Event>
     */
    private function soldOut(?string $city): Collection
    {
        $now = now();
        $end = EventWindows::endSql();

        return $this->availability
            ->whereState($this->base($city), [Availability::SOLD_OUT])
            ->whereRaw("{$end} > ?", [$now->copy()->subDays(self::SOLD_OUT_DAYS)])
            ->orderByRaw("({$end} <= ?) asc", [$now])
            ->orderByRaw("case when {$end} > ? then events.starts_at end asc", [$now])
            ->orderByDesc('starts_at')
            ->limit(self::SHELF)
            ->get();
    }

    /** @return Builder<Event> */
    private function upcoming(?string $city): Builder
    {
        return $this->base($city, withCards: false)->where('starts_at', '>', now());
    }

    /**
     * The shared shape of every section.
     *
     * kind = ticketed is not optional. Invitation events are published so their
     * invited guests can reach them, never so strangers can — a wedding
     * surfacing on the front page is the one bug on this screen that cannot be
     * apologised for.
     *
     * @return Builder<Event>
     */
    private function base(?string $city, bool $withCards = true): Builder
    {
        $query = Event::query()
            ->published()
            ->where('kind', 'ticketed');

        if ($withCards) {
            // Tiers with their counts on them, for the badge on each card: one
            // query for every tier on the shelf (Availability::tiers).
            $query->with([
                'organization:id,name,slug,logo_path,verified_at,verified_name',
                'banner',
                'ticketTypes' => $this->availability->tiers(),
            ]);
        }

        if (filled($city)) {
            // Every spelling of it, as its page and the listing have it.
            $this->places->whereCity($query, $city);
        }

        return $query;
    }

    /** Basis points as a person writes the percentage: 800 is "8", 850 is "8.5". */
    private function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
    }

    private function seconds(): int
    {
        return max(0, (int) config('discovery.cache_seconds', 60));
    }
}
