<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventSummaryResource;
use App\Models\Event;
use App\Services\Discovery\Availability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Nights somebody wants to come back to.
 *
 * A private list, not a public "like": there is no count anywhere, so a quiet
 * night never advertises how quiet it is, and nobody's browsing shows up on
 * somebody else's screen.
 *
 * Saving twice is saving once. A phone with a bad signal will send the same
 * tap twice, and the second one should not be an error a person has to read.
 */
class SavedEventController extends Controller
{
    /** Soonest first, with what has already happened left out. */
    public function index(Request $request)
    {
        $events = Event::query()
            ->join('saved_events', 'saved_events.event_id', '=', 'events.id')
            ->where('saved_events.user_id', $request->user()->id)
            ->where('events.status', 'published')
            ->where('events.starts_at', '>', now())
            // Tiers with their counts, for the badge on each card (Availability).
            ->with(['organization', 'ticketTypes' => app(Availability::class)->tiers(), 'banner'])
            ->orderBy('events.starts_at')
            ->select('events.*')
            ->paginate(20);

        return EventSummaryResource::collection($events);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $event = $this->event($slug);

        DB::table('saved_events')->upsert([
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $request->user()->id,
                'event_id' => $event->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['user_id', 'event_id'], ['updated_at']);

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        $event = $this->event($slug);

        DB::table('saved_events')
            ->where('user_id', $request->user()->id)
            ->where('event_id', $event->id)
            ->delete();

        return response()->json(['saved' => false]);
    }

    /**
     * Only events a person could have found in the first place.
     *
     * An invitation event is reachable by its guests through a token and never
     * by slug, so saving one by slug would confirm it exists.
     */
    private function event(string $slug): Event
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('kind', 'ticketed')
            ->first();

        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        return $event;
    }
}
