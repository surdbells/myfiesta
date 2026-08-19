<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is coming, and who has arrived.
 *
 * Door staff are deliberately excluded by the policy. Scanning is a separate
 * ability tied to one event and does not carry the right to read the list —
 * somebody handed a phone for one night should not also be handed every
 * attendee's name and email.
 */
class GuestController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('viewGuests', $event);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:valid,checked_in'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $guests = Ticket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['valid', 'checked_in'])
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
            )
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Names get typed at a door under time pressure, so matching is
                // loose on both the name and the address.
                $like = '%'.str_replace('%', '\%', mb_strtolower($term)).'%';

                $q->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(holder_name) LIKE ?', [$like])
                        ->orWhereRaw('lower(owner_email) LIKE ?', [$like]);
                });
            })
            ->with('ticketType:id,name')
            ->orderBy('holder_name')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => $guests->getCollection()->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'name' => $ticket->holder_name,
                'email' => $ticket->owner_email,
                'ticket_type' => $ticket->ticketType?->name,
                // The code itself is not listed. A guest list is read on a
                // laptop in an office; a leaked screenshot of it should not be
                // a set of working tickets.
                'checked_in' => $ticket->status === 'checked_in',
                'checked_in_at' => $ticket->checked_in_at,
            ])->values(),
            'meta' => [
                'total' => $guests->total(),
                'checked_in' => Ticket::where('event_id', $event->id)
                    ->where('status', 'checked_in')
                    ->count(),
                'next' => $guests->nextPageUrl(),
            ],
        ]);
    }
}
