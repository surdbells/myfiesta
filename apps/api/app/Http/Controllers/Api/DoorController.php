<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Door\CheckInService;
use App\Services\Door\DoorList;
use App\Services\Door\ScanOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
    public function __construct(
        private readonly CheckInService $door,
        private readonly DoorList $list,
    ) {}

    public function scan(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            // How many of the party are going in now. Absent admits everyone
            // still outstanding, which is the right default for an ordinary
            // single-admission ticket.
            'party' => ['nullable', 'integer', 'min:1', 'max:50'],
            // The scan's own id. A phone that times out waiting for an answer
            // falls back to its offline list and sends the scan again later;
            // this is what stops the retry refusing a guest already let in.
            'client_id' => ['nullable', 'uuid'],
        ]);

        $outcome = $this->door->scan(
            $validated['code'],
            $event->id,
            $request->user(),
            $validated['party'] ?? null,
            $validated['client_id'] ?? null,
        );

        return response()->json($this->present($outcome, $event));
    }

    /**
     * The event's tickets, for a phone to keep deciding with when signal goes.
     *
     * Hashes, not codes — see DoorList. Fetched when the door screen opens and
     * refreshed while it has a connection, so the list is as fresh as the
     * last moment there was signal.
     */
    public function list(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        return response()->json($this->list->for($event));
    }

    /**
     * Scans a door made with no connection, sent once it has one again.
     *
     * A batch, because a door that lost signal for an hour has an hour of
     * scans, and one request per scan on a connection that has only just come
     * back is how the backlog never clears. A batch that does not validate is
     * refused whole, before anything is recorded, so the phone keeps its queue
     * intact; each scan in a valid batch is then decided in its own
     * transaction, one ticket lock at a time.
     *
     * Safe to send twice. Every scan carries the id the phone gave it, and one
     * the server has already recorded comes back with its original result —
     * which is what lets a phone retry a sync whose response it never got.
     */
    public function sync(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        $validated = $request->validate([
            'scans' => ['required', 'array', 'min:1', 'max:500'],
            'scans.*.client_id' => ['required', 'uuid', 'distinct'],
            'scans.*.code' => ['required', 'string', 'max:32'],
            'scans.*.party' => ['nullable', 'integer', 'min:1', 'max:50'],
            'scans.*.offline_result' => ['required', 'in:accepted,duplicate,not_found,void,over_capacity'],
            'scans.*.scanned_at' => ['required', 'date'],
        ]);

        $results = [];

        foreach ($validated['scans'] as $scan) {
            $outcome = $this->door->recordOffline(
                $scan['code'],
                $event->id,
                $request->user(),
                $scan['party'] ?? null,
                $scan['client_id'],
                $scan['offline_result'],
                Carbon::parse($scan['scanned_at']),
            );

            $results[] = ['client_id' => $scan['client_id']] + $this->present($outcome, $event);
        }

        $conflicts = array_values(array_filter($results, fn ($r) => $r['conflict'] !== null));

        return response()->json([
            'data' => $results,
            // Said separately, because it is the only part anybody on the door
            // needs to read: who went in that should not have, and who was
            // turned away that should not have been.
            'conflicts' => $conflicts,
        ]);
    }

    /**
     * Only the organizer path needs authorising.
     *
     * A `door:{event_id}` token already names this event. It was minted
     * deliberately, for one night, and it is the whole grant — that is what
     * lets a venue hand a phone to somebody working the door without first
     * creating them an account and a membership.
     *
     * An organizer token names nothing. It says somebody organizes something,
     * so without this check every organizer on the platform could scan — and
     * now download the ticket list of — every other organizer's event.
     */
    private function authorizeDoor(Request $request, Event $event): void
    {
        $token = $request->user()->currentAccessToken();

        if (! $token->can(TokenAbility::doorFor($event->id))) {
            $this->authorize('scan', $event);
        }
    }

    /** @return array<string, mixed> */
    private function present(ScanOutcome $outcome, Event $event): array
    {
        return [
            'result' => $outcome->result,
            'accepted' => $outcome->admittedAnyone(),
            // How many this scan let in, and how many of the party are still
            // outside. The second is what tells the door whether to keep the
            // ticket open for the rest of a table.
            'admitted' => $outcome->admitted,
            'remaining' => $outcome->remaining,
            'message' => $outcome->message,
            'offline_result' => $outcome->offlineResult,
            'conflict' => $outcome->conflict(),
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
        ];
    }
}
