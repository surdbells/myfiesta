<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Door\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scanning at the door.
 *
 * Reached only with a door- or organizer-scoped token, and a door token is
 * bound to one event — the middleware refuses it for any other. That is the
 * boundary the mobile app cannot enforce for itself, since it carries the
 * organizer interface in the same binary.
 */
class DoorController extends Controller
{
    public function __construct(private readonly CheckInService $door) {}

    public function scan(Request $request, Event $event): JsonResponse
    {
        /*
         * Only the organizer path needs authorising.
         *
         * A `door:{event_id}` token already names this event. It was minted
         * deliberately, for one night, and it is the whole grant — that is what
         * lets a venue hand a phone to somebody working the door without first
         * creating them an account and a membership.
         *
         * An organizer token names nothing. It says somebody organizes
         * something, so without this check every organizer on the platform
         * could scan every other organizer's event — a far worse hole than the
         * 403 this fixes.
         */
        $token = $request->user()->currentAccessToken();

        if (! $token->can(TokenAbility::doorFor($event->id))) {
            $this->authorize('scan', $event);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            // How many of the party are going in now. Absent admits everyone
            // still outstanding, which is the right default for an ordinary
            // single-admission ticket.
            'party' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $outcome = $this->door->scan(
            $validated['code'],
            $event->id,
            $request->user(),
            $validated['party'] ?? null,
        );

        return response()->json([
            'result' => $outcome->result,
            'accepted' => $outcome->admittedAnyone(),
            // How many this scan let in, and how many of the party are still
            // outside. The second is what tells the door whether to keep the
            // ticket open for the rest of a table.
            'admitted' => $outcome->admitted,
            'remaining' => $outcome->remaining,
            'message' => $outcome->message,
            'ticket' => $outcome->ticket && $outcome->ticket->event_id === $event->id
                ? [
                    'holder_name' => $outcome->ticket->holder_name,
                    'type' => $outcome->ticket->ticketType?->name,
                    'admits' => $outcome->ticket->admits,
                    'admitted_count' => $outcome->ticket->admitted_count,
                ]
                // Nothing about a ticket belonging to another event. A door
                // token is scoped to one event, and leaking a guest's name from
                // a different one would be a data leak dressed as helpfulness.
                : null,
        ]);
    }
}
