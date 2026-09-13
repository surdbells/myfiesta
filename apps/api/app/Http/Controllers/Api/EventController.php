<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventSummaryResource;
use App\Models\Event;
use App\Services\Discovery\EventFilters;
use App\Services\Events\CalendarFile;
use App\Services\Discovery\EventSearch;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public discovery. No token, because browsing and buying need no account.
 */
class EventController extends Controller
{
    public function index(Request $request, EventSearch $search)
    {
        $page = $search->query(EventFilters::fromRequest($request));

        $page->getCollection()->loadMissing('ticketTypes');

        return EventSummaryResource::collection($page);
    }

    public function show(string $slug)
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->with(['organization', 'venue', 'banner', 'gallery', 'ticketTypes' => fn ($q) => $q
                ->whereIn('status', ['on_sale', 'sold_out'])
                ->orderBy('sort_order')])
            ->first();

        // Invitation events are reachable by their invited guests through a
        // token, never by slug. Returning 404 rather than 403 keeps the public
        // API from confirming that somebody's wedding exists at all.
        if ($event === null || $event->kind !== 'ticketed') {
            throw new NotFoundHttpException('Event not found.');
        }

        return new EventResource($event);
    }

    /**
     * The event as a calendar file.
     *
     * Published events, and cancelled ones: somebody who added a night that
     * was then called off should be able to fetch it again and see it marked
     * cancelled rather than a 404. Invitation events stay out, as on the page.
     */
    public function calendar(string $slug, CalendarFile $calendar)
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->whereIn('status', ['published', 'cancelled'])
            ->where('kind', 'ticketed')
            ->with('venue')
            ->first();

        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        return response($calendar->for($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$calendar->filename($event).'"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
