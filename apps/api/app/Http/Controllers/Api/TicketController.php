<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Extensions\TicketExtras;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\Disputes\ActivityLog;
use App\Services\Tickets\TicketHandover;
use App\Services\Tickets\TicketHandoverRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        // What each feature adds to a ticket, loaded once for the page.
        app(TicketExtras::class)->prime($tickets->getCollection());

        return TicketResource::collection($tickets);
    }

    /**
     * Hand a ticket to someone else.
     *
     * The previous endpoint took a ticket id and an email from anyone at all,
     * with no proof of ownership, and ticket ids were sequential integers — so
     * reassigning a stranger's ticket was a matter of counting. Ownership is
     * now checked, and the transfer is recorded.
     *
     * Through the same handover as the link in the buyer's email
     * (TicketHandover): a new code, so the one on this phone stops opening
     * the door, and an email to the new holder with a link of their own.
     */
    public function transfer(Request $request, Ticket $ticket, TicketHandover $handover): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        if ($ticket->owner_user_id !== $request->user()->id) {
            return response()->json(['message' => 'That is not your ticket.'], 403);
        }

        try {
            $transfer = $handover->send($ticket, $validated['email'], $validated['name'], $request->user());
        } catch (TicketHandoverRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        // Only what was done. The ticket as it now is carries the new
        // holder's code, which this phone must never be handed.
        return response()->json([
            'message' => "Sent to {$transfer->to_email}. The code on this phone no longer gets anybody in.",
        ]);
    }
}
