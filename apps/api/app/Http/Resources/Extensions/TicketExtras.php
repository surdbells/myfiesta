<?php

namespace App\Http\Resources\Extensions;

use App\Models\Ticket;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;

/**
 * What the features added since put on a ticket, wherever a ticket is shown:
 * the phone's list (TicketResource) and the buyer's link (TicketAccessPayload).
 *
 * One field each, answered by a class of the feature's own, as EventExtras
 * does for an event, and always present.
 *
 * Tickets come in lists, so nothing here may ask the database once per
 * ticket. Each list is handed to prime() first, once, and a feature that
 * needs more than the ticket loads it there for the whole list — eager
 * loading a relation onto the tickets, or keeping what it found on itself,
 * keyed by ticket — and reads it back in its field. A single ticket may
 * arrive unprimed (the answer to sending one on), so each field still
 * answers, with loadMissing or a query of its own, when nothing was loaded
 * for it.
 *
 * One instance for the whole request (Scoped): the list is primed by its
 * controller and each ticket read by its resource, and both must be talking
 * to the same features. A queue worker gets a fresh one for each job. So a
 * feature that keeps something on itself replaces it on every primeTickets(),
 * rather than adding to it, and never trusts it for a ticket it was not
 * given there.
 */
#[Scoped]
class TicketExtras
{
    public function __construct(
        private readonly Perks $perks,
        private readonly Share $share,
        private readonly Transfer $transfer,
    ) {}

    /** @param  Collection<int, Ticket>  $tickets */
    public function prime(Collection $tickets): void
    {
        if ($tickets->isEmpty()) {
            return;
        }

        $this->perks->primeTickets($tickets);
        $this->share->primeTickets($tickets);
        $this->transfer->primeTickets($tickets);
    }

    /** @return array<string, mixed> */
    public function for(Ticket $ticket): array
    {
        return [
            'perks' => $this->perks->forTicket($ticket),
            'share_link' => $this->share->linkFor($ticket),
            'transferable' => $this->transfer->transferable($ticket),
        ];
    }
}
