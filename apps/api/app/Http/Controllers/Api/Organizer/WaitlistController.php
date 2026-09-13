<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\Waitlist;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is waiting for a sold-out event, and telling them.
 */
class WaitlistController extends Controller
{
    public function __construct(private readonly Waitlist $waitlist) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        $entries = WaitlistEntry::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', 'left')
            // Waiting first, in the order they will be told; then the rest.
            ->orderByRaw("status = 'waiting' desc")
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(Paging::perPage($request, 50));

        $counts = WaitlistEntry::query()
            ->where('event_id', $event->id)
            ->selectRaw('status, count(*) as people, sum(quantity) as tickets')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return response()->json([
            'data' => $entries->getCollection()->map(fn (WaitlistEntry $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'quantity' => $e->quantity,
                'status' => $e->status,
                'joined_at' => $e->created_at,
                'notified_at' => $e->notified_at,
            ])->values(),
            'meta' => Paging::meta($entries),
            'summary' => [
                'waiting' => (int) ($counts['waiting']->people ?? 0),
                'waiting_tickets' => (int) ($counts['waiting']->tickets ?? 0),
                'notified' => (int) ($counts['notified']->people ?? 0),
                'purchased' => (int) ($counts['purchased']->people ?? 0),
            ],
            'on_sale' => $this->waitlist->hasTicketsOnSale($event),
        ]);
    }

    /**
     * Tell the people waiting.
     *
     * The organizer chooses how many — releasing twenty tickets to four hundred
     * people sends three hundred and eighty of them to a sold-out page.
     */
    public function notify(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $this->waitlist->hasTicketsOnSale($event)) {
            // Told to come and buy, and nothing to buy, is worse than silence.
            return response()->json([
                'message' => 'Nothing is on sale yet. Release tickets first — reopen a tier or add places — then tell the waitlist.',
            ], 422);
        }

        $told = $this->waitlist->notify($event, $request->user(), $data['limit'] ?? null, $data['note'] ?? null);

        return response()->json([
            'message' => $told === 0 ? 'Nobody is waiting.' : ($told === 1 ? 'Told 1 person.' : "Told {$told} people."),
            'told' => $told,
        ]);
    }
}
