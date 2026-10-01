<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventSummaryResource;
use App\Http\Resources\OrganizerResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Discovery\Availability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page behind an organizer's name.
 *
 * Until this existed, following an organizer led nowhere: the app listed who
 * you follow and there was no page to open, and the only link an organizer
 * could hand out was for one night at a time. Every platform they would
 * otherwise be using has this page, and it is the one a promoter puts in a
 * bio.
 *
 * Public, and no token needed — it is a link meant to be shared. A reader who
 * does have one is told whether they already follow, which is the only thing
 * on here that differs between two people looking at the same page.
 */
class OrganizerController extends Controller
{
    /**
     * How many past nights are shown at a time.
     *
     * Enough to prove a name is real, short enough that the page is still
     * about what is on. The rest come twelve more at a time when somebody
     * asks for them (events()), so a promoter with five years behind them
     * never hands a stranger three hundred cards to scroll past what is on.
     */
    public const PAST = 12;

    public function show(Request $request, string $slug)
    {
        $organization = $this->organization($slug);

        $upcoming = $this->upcoming($organization)->get();

        // One more than is shown, to know whether there is a next page
        // without counting all of them.
        $past = $this->past($organization)->limit(self::PAST + 1)->get();
        $morePast = $past->count() > self::PAST;
        $past = $past->take(self::PAST)->values();

        return new OrganizerResource($organization, $upcoming, $past, $morePast);
    }

    /**
     * Their nights, a page at a time: ?when=past&page=2.
     *
     * What "Show more past events" asks for. The first page of past nights is
     * the twelve the organizer page already carries; the same nights a
     * stranger may see, in the same order, so paging on from there neither
     * repeats nor skips one. `when=upcoming` pages what is on the same way,
     * soonest first, for a client that wants to; the organizer page itself
     * carries all of those, unpaged.
     *
     * An organizer with no page has none of these either.
     */
    public function events(Request $request, string $slug): JsonResponse
    {
        $query = $request->validate([
            'when' => ['required', 'string', 'in:past,upcoming'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $organization = $this->organization($slug);

        $page = (int) ($query['page'] ?? 1);

        $nights = ($query['when'] === 'past'
            ? $this->past($organization)
            : $this->upcoming($organization))
            ->offset(($page - 1) * self::PAST)
            ->limit(self::PAST + 1)
            ->get();

        return response()->json([
            'data' => EventSummaryResource::collection($nights->take(self::PAST))->resolve($request),
            'meta' => [
                'page' => $page,
                'per_page' => self::PAST,
                'has_more' => $nights->count() > self::PAST,
            ],
        ]);
    }

    /**
     * The organization behind a slug, if it has a page.
     *
     * An account that has never published anything has no page. Signing up
     * is not publishing. Without this, every organization that ever
     * registered would answer 200 at a guessable address, which is both a
     * thin page for a crawler to index and a way to confirm that a name is
     * taken.
     */
    private function organization(string $slug): Organization
    {
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null || ! $this->listed($organization)->exists()) {
            throw new NotFoundHttpException('Organizer not found.');
        }

        return $organization;
    }

    /** Nights still to come, soonest first, then by id so a page boundary never moves. */
    private function upcoming(Organization $organization): Builder
    {
        return $this->listed($organization)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    /** Nights that have happened, newest first, then by id so a page boundary never moves. */
    private function past(Organization $organization): Builder
    {
        return $this->listed($organization)
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }

    /**
     * The nights of theirs a stranger may see.
     *
     * kind = ticketed is not optional here, as everywhere else public: an
     * invitation event is published so its invited guests can reach it by
     * token, and listing somebody's wedding under the organizer who is
     * catering it would be the worst bug on this page.
     */
    private function listed(Organization $organization): Builder
    {
        return Event::query()
            ->where('organization_id', $organization->id)
            ->published()
            ->where('kind', 'ticketed')
            // Tiers with their counts, for the badge on each card (Availability).
            ->with(['banner', 'ticketTypes' => app(Availability::class)->tiers()])
            // The summary renders the organizer's own name on each card; it is
            // the same organization on every one of them, so it is handed over
            // rather than loaded per row.
            ->with(['organization:id,name,slug']);
    }
}
