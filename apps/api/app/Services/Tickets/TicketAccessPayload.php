<?php

namespace App\Services\Tickets;

use App\Models\Order;
use App\Models\Ticket;
use App\Services\Events\CalendarFile;
use App\Services\Receipts\Receipt;
use App\Services\Resale\Resale;

/**
 * What a buyer sees behind the link in their email.
 *
 * Its own class because two things answer with it: the page itself, and
 * anything that changes what is on the page — giving a ticket back changes
 * the QR, the code and what can be done next, and an action that returns the
 * new page is one description of that state instead of a client rebuilding
 * it from a message.
 */
class TicketAccessPayload
{
    public function __construct(private readonly QrEncoder $qr) {}

    /** @return array<string, mixed> */
    public function for(Order $order): array
    {
        $order->loadMissing(['event.venue', 'event.organization', 'tickets.ticketType', 'lines']);

        $event = $order->event;
        $qr = $this->qr;

        return [
            'reference' => $order->reference,
            'status' => $order->status,
            'buyer_name' => $order->buyer_name,
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'timezone' => $event->timezone,
                'venue' => $event->venue?->name ?? $event->city,
                'address' => $event->venue?->address_line,
                'city' => $event->city,
                'min_age' => $event->min_age,
                'id_required' => $event->id_required,
                'organizer' => $event->organization->name,
                'calendar' => app(CalendarFile::class)->links($event),
            ],
            /*
             * What else is on the order.
             *
             * An add-on has no code and nothing to scan — it is claimed at a
             * bar or a cloakroom by somebody looking at this screen, so it
             * has to be on this screen. Without it a buyer has paid for two
             * bottles and holds no evidence of it.
             */
            'extras' => $order->lines
                ->filter(fn ($line) => ! $line->isTicket())
                ->map(fn ($line) => [
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                ])
                ->values(),

            // What was paid, to whom, and each tax on it — the same receipt
            // the confirmation email carries, from the order's own copy of
            // how it was priced. No ticket codes in it.
            'receipt' => Receipt::for($order)->toArray(),

            // Only the tickets the buyer still holds. One handed to somebody
            // else is theirs now, and after a reissue its new code must not
            // reach this link (BuyersTickets).
            'tickets' => BuyersTickets::of($order, $order->tickets)
                // Refunded tickets are not shown. A QR that will be turned away
                // at the door is worse than no QR, because the holder does not
                // find out until they are at the front of the queue.
                ->whereNotIn('status', ['refunded', 'void'])
                ->map(fn (Ticket $ticket) => [
                    'id' => $ticket->id,
                    // A ticket waiting to be taken has no working code, and
                    // showing one would be showing a QR that fails at a door.
                    'code' => $ticket->status === 'listed' ? null : $ticket->code,
                    'type' => $ticket->ticketType?->name,
                    'holder' => $ticket->holder_name,
                    'status' => $ticket->status,
                    // How many people this one lets in, and how many of them
                    // are already inside — a table that arrives in two groups
                    // needs to see that four of five have been admitted.
                    'admits' => $ticket->admits,
                    'admitted' => $ticket->admitted_count,
                    'qr' => $ticket->status === 'listed' ? null : $qr->svg($ticket->code),
                    /*
                     * Whether this one can be handed back, and if not, why.
                     *
                     * The reason travels with the ticket rather than being
                     * worked out again by the client: "not until it is closer"
                     * and "the organizer does not allow it" are different
                     * sentences and only the server knows which applies.
                     */
                    'return' => [
                        'listed' => $ticket->status === 'listed',
                        'refusal' => app(Resale::class)->refusal($ticket, $event),
                    ],
                ])
                ->values(),
        ];
    }
}
