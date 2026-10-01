<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\Events\EventDuplicator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Same event, new date": a night copied into a new draft.
 *
 * On its own, out of EventController, so duplicating with changes and
 * starting from a template (routes/api/clone.php) are added here without
 * editing the controller that shows and edits an event.
 */
class EventCopyController extends Controller
{
    /**
     * Copy an event into a new draft.
     *
     * Authorised as a create against the same organization, not as an update
     * of the original: this makes a new event, and somebody who may read an
     * event must not be able to mint one from it.
     */
    public function duplicate(Request $request, Event $event, EventDuplicator $duplicator): JsonResponse
    {
        $this->authorize('viewInConsole', $event);
        $this->authorize('create', [Event::class, $event->organization_id]);

        // A copy would carry no takedown, and publish() only refuses the
        // event that does: copying is the takedown undone in two calls.
        if ($event->taken_down_at !== null) {
            return response()->json([
                'message' => 'myFiesta has taken this event off sale, so it cannot be copied until that is lifted. '
                    .'Reply to the email we sent to have it looked at again.',
            ], 422);
        }

        $data = $request->validate([
            'starts_at' => ['nullable', 'date', 'after:now'],
            'title' => ['nullable', 'string', 'max:160'],
        ], [
            'starts_at.after' => 'Pick a date in the future for the copy.',
        ]);

        $copy = $duplicator->duplicate(
            $event,
            isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            $data['title'] ?? null,
            $request->user(),
        );

        return response()->json(
            new EventResource($copy->load(['organization', 'ticketTypes'])),
            201,
        );
    }
}
