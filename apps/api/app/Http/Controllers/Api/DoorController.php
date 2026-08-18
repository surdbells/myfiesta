<?php

namespace App\Http\Controllers\Api;

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
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $outcome = $this->door->scan($validated['code'], $event->id, $request->user());

        return response()->json([
            'result' => $outcome->result,
            'admitted' => $outcome->admitted(),
            'message' => $outcome->message,
            'ticket' => $outcome->ticket && $outcome->ticket->event_id === $event->id
                ? [
                    'holder_name' => $outcome->ticket->holder_name,
                    'type' => $outcome->ticket->ticketType?->name,
                ]
                // Nothing about a ticket belonging to another event. A door
                // token is scoped to one event, and leaking a guest's name from
                // a different one would be a data leak dressed as helpfulness.
                : null,
        ]);
    }
}
