<?php

namespace App\Services\Tickets;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which of an order's tickets are still the buyer's.
 *
 * A ticket handed to somebody else belongs to them from then on, whether the
 * holder sent it on themselves or support reissued it. The buyer's link and
 * any email sent to the buyer stop showing it. After a reissue with a new
 * code this is what makes the new code mean anything: otherwise the order
 * link, which never expires, hands it straight back to the address it was
 * taken from.
 *
 * This is decided by the transfer record, not by comparing addresses alone. An
 * account that moves to a new address takes its tickets with it
 * (AccountController), so a ticket whose address no longer matches the order,
 * with no transfer behind it, is still the buyer's own.
 */
final class BuyersTickets
{
    /**
     * @param  Collection<int, Ticket>  $tickets  tickets on $order
     * @return Collection<int, Ticket>
     */
    public static function of(Order $order, Collection $tickets): Collection
    {
        $buyer = self::normalise($order->buyer_email);

        $elsewhere = $tickets->filter(fn (Ticket $ticket) => self::normalise($ticket->owner_email) !== $buyer);

        if ($elsewhere->isEmpty()) {
            return $tickets->values();
        }

        $handedOn = TicketTransfer::query()
            ->whereIn('ticket_id', $elsewhere->map(fn (Ticket $ticket) => $ticket->getKey())->all())
            ->distinct()
            ->pluck('ticket_id')
            ->flip();

        return $tickets
            ->reject(fn (Ticket $ticket) => $handedOn->has($ticket->id)
                && self::normalise($ticket->owner_email) !== $buyer)
            ->values();
    }

    private static function normalise(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }
}
