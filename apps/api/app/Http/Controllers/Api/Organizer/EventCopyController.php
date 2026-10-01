<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\CopyAdjustments;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\Events\EventDuplicator;
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
     * Copy an event into a new draft, with the changes asked for.
     *
     * Nothing but a date is needed: the rest of the body — a title, an end, a
     * description, which tiers and at what price, whether the extras,
     * questions and reminders come too — is each left as the original has it
     * when it is not given (CopyAdjustments).
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

        [$startsAt, $options] = CopyAdjustments::read(
            $request,
            $event->ticketTypes()->pluck('id')->map(fn ($id) => (string) $id)->all(),
            $event->starts_at,
            'this event',
        );

        $copy = $duplicator->duplicate($event, $startsAt, null, $request->user(), $options);

        return response()->json(self::made($copy, $request), 201);
    }

    /**
     * The new draft, as the public page will show it, with what the console
     * needs to open it: its id, and that it is a draft.
     *
     * @return array<string, mixed>
     */
    public static function made(Event $copy, Request $request): array
    {
        return [
            ...(new EventResource($copy->load(['organization', 'ticketTypes'])))->resolve($request),
            'id' => $copy->id,
            'status' => $copy->status,
        ];
    }
}
