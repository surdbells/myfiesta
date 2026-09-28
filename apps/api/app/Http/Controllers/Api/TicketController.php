<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\Disputes\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An attendee's own tickets.
 */
class TicketController extends Controller
{
    public function index(Request $request)
    {
        $tickets = Ticket::query()
            // Qualified, because the join brings in events.status alongside
            // tickets.status and Postgres will not guess which was meant.
            ->where('tickets.owner_user_id', $request->user()->id)
            ->whereIn('tickets.status', ['valid', 'checked_in'])
            // The order too, for the receipt on tickets this account bought.
            ->with(['event.venue', 'ticketType', 'order'])
            // Soonest first: the one you need next is the one you want on
            // screen when you open the app at a door.
            ->join('events', 'events.id', '=', 'tickets.event_id')
            ->orderBy('events.starts_at')
            ->select('tickets.*')
            // After the select, which would otherwise drop it: whether each
            // ticket was ever handed on, which the receipt asks of a ticket
            // from an order placed without an account.
            ->withExists('transfers')
            ->paginate(50);

        // The app draws each of these as a QR, so each goes into its ticket
        // history: the ticket was on this person's phone (ActivityLog).
        app(ActivityLog::class)->shownInApp($tickets->getCollection(), $request);

        return TicketResource::collection($tickets);
    }

    /**
     * Hand a ticket to someone else.
     *
     * The previous endpoint took a ticket id and an email from anyone at all,
     * with no proof of ownership, and ticket ids were sequential integers — so
     * reassigning a stranger's ticket was a matter of counting. Ownership is
     * now checked, and the transfer is recorded.
     */
    public function transfer(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        if ($ticket->owner_user_id !== $request->user()->id) {
            return response()->json(['message' => 'That is not your ticket.'], 403);
        }

        if ($ticket->status !== 'valid') {
            // A checked-in ticket has already been used; transferring it would
            // be handing over an empty envelope.
            return response()->json([
                'message' => $ticket->status === 'checked_in'
                    ? 'This ticket has already been used.'
                    : 'This ticket can no longer be transferred.',
            ], 422);
        }

        $recipient = DB::transaction(function () use ($ticket, $validated, $request) {
            // Deactivated accounts included: one keeps its address, and a
            // second row for it would break the unique index on email.
            $recipient = User::withTrashed()->firstOrCreate(
                ['email' => strtolower(trim($validated['email']))],
                ['name' => $validated['name'], 'password' => null],
            );

            TicketTransfer::create([
                'ticket_id' => $ticket->id,
                'from_email' => $ticket->owner_email,
                'to_email' => $recipient->email,
                'initiated_by' => $request->user()->id,
                'transferred_at' => now(),
            ]);

            $ticket->update([
                'owner_user_id' => $recipient->id,
                'owner_email' => $recipient->email,
                'holder_name' => $validated['name'],
            ]);

            return $recipient;
        });

        return response()->json([
            'message' => "Sent to {$recipient->email}.",
            'ticket' => new TicketResource($ticket->fresh()->load(['event.venue', 'ticketType'])),
        ]);
    }
}
