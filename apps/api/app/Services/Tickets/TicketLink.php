<?php

namespace App\Services\Tickets;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketTransfer;

/**
 * What a ticket link opens: an order, or one ticket somebody was sent.
 *
 * The order's link went to whoever paid, and opens every ticket still theirs
 * along with the receipt. A ticket sent on (TicketHandover) comes with a link
 * of its own, which opens that one ticket and nothing else of the order's —
 * no receipt, no add-ons, no reference to look the order up by — for as long
 * as the ticket is still with the person it was sent to. Sending it on again
 * closes it (the handover clears the token), and so does the ticket moving
 * away from them any other way, and their asking to be erased.
 *
 * One answer for a wrong token and a closed one: telling them apart would let
 * somebody test tokens by the response.
 */
final class TicketLink
{
    private function __construct(
        public readonly ?Order $order,
        public readonly ?TicketTransfer $transfer,
    ) {}

    public static function find(string $token): ?self
    {
        $order = Order::where('access_token', $token)->first();

        if ($order !== null) {
            return new self($order, null);
        }

        $transfer = TicketTransfer::query()->with('ticket')->where('access_token', $token)->first();

        return $transfer !== null && self::open($transfer) ? new self(null, $transfer) : null;
    }

    /**
     * Whether a sent ticket's link still opens it.
     *
     * Still theirs by account, not by address: the addresses on a transfer
     * are cleared when the person who sent it asks to be erased, and that is
     * no reason for the person it went to to lose their ticket page.
     */
    public static function open(TicketTransfer $transfer): bool
    {
        $ticket = $transfer->ticket;

        return $transfer->access_token !== null
            && $transfer->to_user_id !== null
            && $ticket !== null
            && ! in_array($ticket->status, ['refunded', 'void'], true)
            && $ticket->owner_user_id === $transfer->to_user_id;
    }

    /**
     * A ticket on this link that its holder may act on, or null.
     *
     * On an order's link, one on the order that is still the buyer's: one
     * handed to somebody else is theirs, not the link's (BuyersTickets). On a
     * sent ticket's link, that ticket.
     */
    public function ticket(string $ticketId): ?Ticket
    {
        if ($this->transfer !== null) {
            return $this->transfer->ticket_id === $ticketId ? $this->transfer->ticket : null;
        }

        $ticket = Ticket::query()
            ->with('event')
            ->whereKey($ticketId)
            ->where('order_id', $this->order?->id)
            ->first();

        if ($ticket === null || $this->order === null || BuyersTickets::of($this->order, collect([$ticket]))->isEmpty()) {
            return null;
        }

        return $ticket;
    }
}
