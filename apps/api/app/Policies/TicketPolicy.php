<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * A ticket belongs to whoever holds it.
 *
 * The previous transfer endpoint took a ticket id and a new email from anyone
 * at all, with ticket ids being sequential integers. Ownership plus this policy
 * is what closes that.
 */
class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket);
    }

    public function transfer(User $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket)
            && $ticket->isValid()
            && ! $ticket->isCheckedIn();
    }

    /**
     * Matched on account or on address.
     *
     * Guest checkout means a buyer may never register, so an unclaimed ticket
     * is still theirs by email. Reaching it that way requires a signed link
     * rather than merely asserting the address, which is the flaw in the
     * scheme this replaces.
     */
    private function owns(User $user, Ticket $ticket): bool
    {
        if ($ticket->owner_user_id !== null) {
            return $ticket->owner_user_id === $user->id;
        }

        return strcasecmp($ticket->owner_email, $user->email) === 0;
    }
}
