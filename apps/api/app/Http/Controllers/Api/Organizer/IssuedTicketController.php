<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\TicketIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Issuing a ticket by hand.
 *
 * Comps, guest list, the promoter's cousin, somebody who paid in cash at the
 * door. Organizers do this in their first week and had no way to until now —
 * the machinery has existed since Phase 2 because RSVP uses the same path.
 *
 * A hand-issued ticket is an ordinary ticket. Same code format, same door, same
 * scan log, same partial-admission counting for a table. The previous platform
 * had a separate path for free tickets, which is how magic values like
 * `_sale = "0"` ended up in the middle of its tickets table.
 */
class IssuedTicketController extends Controller
{
    public function __construct(private readonly TicketIssuer $issuer) {}

    public function store(Request $request, Event $event): JsonResponse
    {
        // Minting entry is not the same as reading the guest list. Marketing
        // can message attendees and cannot create them.
        $this->authorize('update', $event);

        $data = $request->validate([
            'ticket_type_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'note' => ['nullable', 'string', 'max:255'],
            // Off by default. An organizer adding forty names to a guest list
            // rarely wants forty emails leaving immediately, and a comp handed
            // over in person needs no email at all.
            'send_email' => ['nullable', 'boolean'],
        ]);

        $type = $event->ticketTypes()->whereKey($data['ticket_type_id'])->first();

        if ($type === null) {
            return response()->json(['message' => 'That ticket type is not on this event.'], 404);
        }

        $quantity = $data['quantity'] ?? 1;

        $tickets = DB::transaction(function () use ($event, $type, $data, $quantity) {
            // Comps consume real capacity. A venue holds the number of people it
            // holds, and a guest list that does not count against the room is
            // how an event sells to its limit and then admits forty more.
            $this->assertRoomFor($type, $quantity);

            $issued = [];

            for ($i = 0; $i < $quantity; $i++) {
                $issued[] = $this->issuer->issueComp(
                    eventId: $event->id,
                    ticketTypeId: $type->id,
                    email: $data['email'],
                    name: $data['name'],
                );
            }

            return $issued;
        });

        if ($data['send_email'] ?? false) {
            Mail::to($data['email'])->send(new TicketIssued($event, $tickets, $data['note'] ?? null));
        }

        return response()->json([
            'message' => $quantity === 1
                ? 'Ticket issued.'
                : "{$quantity} tickets issued.",
            'tickets' => array_map(fn (Ticket $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'admits' => $t->admits,
                'holder_name' => $t->holder_name,
            ], $tickets),
        ], 201);
    }

    /**
     * Refuse rather than oversell.
     *
     * Counted the same way checkout counts it — issued tickets plus live holds
     * — so a comp and a sale cannot both claim the last place.
     */
    private function assertRoomFor(TicketType $type, int $quantity): void
    {
        if ($type->quantity_available === null) {
            return;
        }

        $locked = TicketType::query()->whereKey($type->id)->lockForUpdate()->first();

        $issued = Ticket::where('ticket_type_id', $type->id)
            ->whereIn('status', ['valid', 'checked_in'])
            ->count();

        $held = (int) DB::table('inventory_holds')
            ->where('ticket_type_id', $type->id)
            ->where('expires_at', '>', now())
            ->sum('quantity');

        $remaining = $locked->quantity_available - $issued - $held;

        abort_if(
            $remaining < $quantity,
            422,
            $remaining <= 0
                ? "{$locked->name} has sold out — none left to issue."
                : "Only {$remaining} of {$locked->name} left to issue.",
        );
    }
}
