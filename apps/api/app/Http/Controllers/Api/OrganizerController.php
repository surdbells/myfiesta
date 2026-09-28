<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizerResource;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Discovery\Availability;
use Illuminate\Database\Eloquent\Builder;
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
     * How many past nights are shown.
     *
     * Enough to prove a name is real, short enough that the page is still
     * about what is on. Everything older is still reachable through its own
     * link — nothing is hidden, it is just not the front of this page.
     */
    public const PAST = 12;

    public function show(Request $request, string $slug)
    {
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            throw new NotFoundHttpException('Organizer not found.');
        }

        $upcoming = $this->events($organization)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        $past = $this->events($organization)
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->limit(self::PAST)
            ->get();

        /*
         * An account that has never published anything has no page.
         *
         * Signing up is not publishing. Without this, every organization that
         * ever registered would answer 200 at a guessable address, which is
         * both a thin page for a crawler to index and a way to confirm that a
         * name is taken.
         */
        if ($upcoming->isEmpty() && $past->isEmpty()) {
            throw new NotFoundHttpException('Organizer not found.');
        }

        return new OrganizerResource($organization, $upcoming, $past);
    }

    /**
     * The nights of theirs a stranger may see.
     *
     * kind = ticketed is not optional here, as everywhere else public: an
     * invitation event is published so its invited guests can reach it by
     * token, and listing somebody's wedding under the organizer who is
     * catering it would be the worst bug on this page.
     */
    private function events(Organization $organization): Builder
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
