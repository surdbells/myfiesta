<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Perks bought with Fiesta Points: what a night offers, on its event page,
 * and what a ticket's holder has claimed, on the ticket.
 *
 * Belongs to the points feature. Empty until it says otherwise.
 */
class Perks
{
    /** @return list<array<string, mixed>> */
    public function forEvent(Event $event, Request $request): array
    {
        return [];
    }

    /** @param  Collection<int, Ticket>  $tickets */
    public function primeTickets(Collection $tickets): void {}

    /** @return list<mixed> */
    public function forTicket(Ticket $ticket): array
    {
        return [];
    }
}
