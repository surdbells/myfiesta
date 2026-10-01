<?php

namespace App\Services\Tickets;

use App\Http\Resources\Extensions\TicketExtras;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Services\Events\CalendarFile;
use App\Services\Receipts\Receipt;
use App\Services\Resale\Resale;

/**
 * What a buyer sees behind the link in their email, and what somebody sent
 * one of their tickets sees behind theirs.
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

        // Only the tickets the buyer still holds. One handed to somebody
        // else is theirs now, and after a reissue its new code must not
        // reach this link (BuyersTickets). Refunded tickets are not shown. A
        // QR that will be turned away at the door is worse than no QR,
        // because the holder does not find out until they are at the front
        // of the queue.
        $tickets = BuyersTickets::of($order, $order->tickets)
            ->whereNotIn('status', ['refunded', 'void'])
            ->values();

        // What each feature adds to a ticket, loaded once for the order.
        $extras = app(TicketExtras::class);
        $extras->prime($tickets);

        return [
            'reference' => $order->reference,
            'status' => $order->status,
            'buyer_name' => $order->buyer_name,
            // The order's own page, not one ticket somebody was sent.
            'sent' => false,
            'event' => [
                ...$this->event($event),
                // The file through this order's own link rather than the
                // event's, so that adding the night to a calendar goes into
                // the order's ticket history (TicketAccessController).
                'calendar' => [
                    ...app(CalendarFile::class)->links($event),
                    'ics_url' => url('/api/tickets/'.rawurlencode($order->access_token).'/calendar.ics'),
                ],
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

            'tickets' => $tickets
                ->map(fn (Ticket $ticket) => $this->ticket($ticket, $event, $extras, app(Resale::class)->refusal($ticket, $event)))
                ->values(),
        ];
    }

    /**
     * One ticket somebody was sent, behind the link that came with it.
     *
     * That ticket and nothing else of the order's: no receipt, no add-ons,
     * no buyer's name, no order status, and no reference, which is enough to
     * look the order's total up (OrderStatusController). The person reading
     * it did not pay, and what somebody else paid is not theirs to see. The
     * calendar file is the event's own, not the order's: the order's link is
     * its buyer's.
     *
     * Empty of tickets once the link has closed (TicketLink) — the answer to
     * sending it on from this page, which is the last thing the page shows.
     *
     * @return array<string, mixed>
     */
    public function forTransfer(TicketTransfer $transfer): array
    {
        $transfer->loadMissing(['ticket.ticketType', 'ticket.order', 'ticket.event.venue', 'ticket.event.organization']);

        $ticket = $transfer->ticket;
        $event = $ticket->event;

        $tickets = collect(TicketLink::open($transfer) ? [$ticket] : []);

        $extras = app(TicketExtras::class);
        $extras->prime($tickets);

        return [
            'reference' => null,
            'status' => null,
            'buyer_name' => null,
            'sent' => true,
            'event' => [
                ...$this->event($event),
                'calendar' => app(CalendarFile::class)->links($event),
            ],
            'extras' => [],
            'receipt' => null,
            // Giving it back would repay whoever paid for it, from a page
            // they are not on; sending it on is what this holder can do.
            'tickets' => $tickets
                ->map(fn (Ticket $held) => $this->ticket($held, $event, $extras,
                    'This ticket was sent to you, so it can be passed on but not given back.'))
                ->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function event(Event $event): array
    {
        return [
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
        ];
    }

    /** @return array<string, mixed> */
    private function ticket(Ticket $ticket, Event $event, TicketExtras $extras, ?string $returnRefusal): array
    {
        return [
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
            'qr' => $ticket->status === 'listed' ? null : $this->qr->svg($ticket->code),
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
                'refusal' => $returnRefusal,
            ],
            // Each feature's own field (TicketExtras), as on the phone.
            ...$extras->for($ticket),
        ];
    }
}
