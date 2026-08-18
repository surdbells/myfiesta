<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventSummaryResource;
use App\Models\Event;
use App\Services\Discovery\EventFilters;
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
            ->with(['organization', 'venue', 'ticketTypes' => fn ($q) => $q
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
}
