<?php

namespace App\Http\Resources\Extensions;

use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Whether a ticket can be sent to somebody else from where it is shown.
 *
 * Belongs to the transfer feature. Null until it says otherwise: the server
 * has not said, and a client decides as it did before there was a field.
 */
class Transfer
{
    /** @param  Collection<int, Ticket>  $tickets */
    public function primeTickets(Collection $tickets): void {}

    public function transferable(Ticket $ticket): ?bool
    {
        return null;
    }
}
