<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventSummaryResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything a home page needs, in one request.
 *
 * The public site had no home page — the root was the same flat search list as
 * /events, so somebody arriving without a link had nothing to look at and
 * nothing to browse by. The previous platform had six screens for this: home,
 * featured, upcoming, category, location and listing.
 *
 * One endpoint rather than four, because this is the first screen a stranger
 * sees and four round trips on a phone is four chances to look broken. The
 * sections are small and bounded; the full list is still behind /api/events
 * with its filters.
 */
class DiscoverController extends Controller
{
    /** Small enough to render above the fold, large enough to look alive. */
    private const FEATURED = 6;

    private const UPCOMING = 12;

    public function __invoke(Request $request): JsonResponse
    {
        $city = $request->query('city');

        return response()->json([
            'featured' => EventSummaryResource::collection($this->featured($city)),
            'upcoming' => EventSummaryResource::collection($this->upcoming($city)),
            'cities' => $this->cities(),
            'categories' => $this->categories(),
        ]);
    }

    /**
     * What the platform is putting its weight behind.
     *
     * Falls back to whatever is next when nothing is flagged. An empty hero on
     * the front page reads as a dead site, and a new market has no featured
     * events by definition.
     */
    private function featured(?string $city)
    {
        $featured = $this->base($city)
            ->where('is_featured', true)
            ->orderBy('starts_at')
            ->limit(self::FEATURED)
            ->get();

        return $featured->isNotEmpty()
            ? $featured
            : $this->base($city)->orderBy('starts_at')->limit(self::FEATURED)->get();
    }

    private function upcoming(?string $city)
    {
        return $this->base($city)->orderBy('starts_at')->limit(self::UPCOMING)->get();
    }

    /**
     * Cities with something on, and how much.
     *
     * Counted rather than listed from a table of places: a city with no events
     * is a dead end, and offering one is worse than not offering the filter.
     */
    private function cities(): array
    {
        return Event::query()
            ->published()
            ->where('kind', 'ticketed')
            ->where('starts_at', '>', now())
            ->selectRaw('city, country, count(*) as events')
            ->groupBy('city', 'country')
            ->orderByDesc('events')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'city' => $row->city,
                'country' => $row->country,
                'events' => (int) $row->events,
            ])
            ->all();
    }

    private function categories(): array
    {
        return Event::query()
            ->published()
            ->where('kind', 'ticketed')
            ->where('starts_at', '>', now())
            ->whereNotNull('category')
            ->selectRaw('category, count(*) as events')
            ->groupBy('category')
            ->orderByDesc('events')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'events' => (int) $row->events])
            ->all();
    }

    /**
     * The shared shape of every section.
     *
     * kind = ticketed is not optional. Invitation events are published so their
     * invited guests can reach them, never so strangers can — a wedding
     * surfacing on the front page is the one bug on this screen that cannot be
     * apologised for.
     */
    private function base(?string $city)
    {
        $query = Event::query()
            ->published()
            ->where('kind', 'ticketed')
            ->where('starts_at', '>', now())
            ->with(['organization:id,name,slug,logo_path', 'banner', 'ticketTypes']);

        if (filled($city)) {
            $query->where('city', $city);
        }

        return $query;
    }
}
