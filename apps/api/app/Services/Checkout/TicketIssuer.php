<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;

/**
 * Mints individual tickets.
 *
 * Two things the previous platform got wrong are fixed here.
 *
 * Codes come from random_int, a CSPRNG, not str_shuffle. The old generator was
 * seeded predictably and had no uniqueness constraint, and since validation
 * needed only a code plus a publicly obtainable organizer id, forging entry was
 * tractable.
 *
 * Every ticket has an owner. Accounts are optional and ownership is not: a
 * ticket belongs to an identity, claimed or unclaimed. That is what makes
 * transfer an authenticated act rather than a public endpoint keyed on a
 * sequential integer, and it is the precondition for controlled resale.
 */
class TicketIssuer
{
    /**
     * Excludes every character that is misread aloud or on a phone screen at a
     * dark, loud door: O/0, I/1, S/5, Z/2, B/8, G/6, U/V.
     *
     * Only one of each confusable pair survives, so a code read out over noise
     * has a single spelling. 24 characters still gives ~10^16 combinations
     * across the 12-character format.
     */
    private const ALPHABET = 'ACDEFHJKLMNPQRTVWXY34789';

    /** @return list<Ticket> */
    public function issueFor(Order $order): array
    {
        /*
         * An unclaimed account, so the buyer's tickets are already waiting if
         * they later register with the same address. Creating it now is free;
         * reconstructing the link afterwards means matching on email anyway.
         *
         * Null when nobody gave an address, which only a door sale may do.
         * The ticket then belongs to nobody, is reachable by no link, and can
         * be transferred by nobody — which is exactly what a walk-up scanned
         * in on the spot is.
         *
         * withTrashed: an account staff deactivated keeps its address, and a
         * second row for it would break the unique index on email.
         */
        $owner = $order->user ?? (filled($order->buyer_email)
            ? User::withTrashed()->firstOrCreate(
                ['email' => $order->buyer_email],
                ['name' => $order->buyer_name, 'password' => null],
            )
            : null);

        $tickets = [];

        /*
         * Whether anybody was asked anything about themselves.
         *
         * Checked once rather than per ticket: on the ordinary order, where
         * the organizer asks nothing, this is one query and the loop below
         * touches no answers at all.
         */
        $named = $order->answers()->whereNotNull('order_line_id')->exists();

        /*
         * Ticket lines only, and the filter is the rule rather than a
         * convenience: an add-on admits nobody. Two bottles on a table are
         * two on the order and nothing at the door, so minting a ticket for
         * them would put a stranger through it.
         */
        $ticketLines = $order->lines()->whereNotNull('ticket_type_id')->with('ticketType:id,admits')->get();

        foreach ($ticketLines as $line) {
            for ($i = 0; $i < $line->quantity; $i++) {
                $ticket = Ticket::create([
                    'code' => $this->code(),
                    'event_id' => $order->event_id,
                    'ticket_type_id' => $line->ticket_type_id,
                    // Snapshotted, not read through the type at the door. An
                    // organizer editing a Table of 5 down to a Table of 4 next
                    // month must not silently shrink a table already sold.
                    'admits' => $line->ticketType?->admits ?? 1,
                    'order_id' => $order->id,
                    'owner_user_id' => $owner?->id,
                    'owner_email' => $order->buyer_email,
                    'holder_name' => $order->buyer_name,
                    'status' => 'valid',
                ]);

                /*
                 * Tie this ticket to the person the buyer described.
                 *
                 * The answers were given before any ticket existed — a buyer
                 * fills the form in on the way to a payment page, and the
                 * tickets are minted by the webhook that comes back. They were
                 * stored against the line and a position within it, and this
                 * loop mints them in that same order, so $i is that position.
                 *
                 * Doing it here rather than by matching later is what lets a
                 * door scan one code and read what that person answered.
                 */
                if ($named) {
                    OrderAnswer::query()
                        ->where('order_line_id', $line->id)
                        ->where('attendee_index', $i)
                        ->update(['ticket_id' => $ticket->id]);
                }

                $tickets[] = $ticket;
            }
        }

        return $tickets;
    }

    /**
     * Issue a ticket with no order behind it: a comp, an RSVP, a guest list.
     *
     * Deliberately the same minting path. The previous platform had separate
     * code for free tickets and RSVPs, which is how they ended up with magic
     * values — `_sale = "0"` and a hardcoded ticket type of 17 — sitting in the
     * middle of the tickets table.
     */
    public function issueComp(
        string $eventId,
        string $ticketTypeId,
        string $email,
        string $name,
    ): Ticket {
        // Deactivated accounts included, as in issueFor().
        $owner = User::withTrashed()->firstOrCreate(
            ['email' => strtolower(trim($email))],
            ['name' => $name, 'password' => null],
        );

        return Ticket::create([
            'code' => $this->code(),
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'admits' => TicketType::whereKey($ticketTypeId)->value('admits') ?? 1,
            // Set rather than left to the column default, so the model handed
            // back reflects the row that exists instead of needing a refresh.
            'admitted_count' => 0,
            'order_id' => null,
            'owner_user_id' => $owner->id,
            'owner_email' => strtolower(trim($email)),
            'holder_name' => $name,
            'status' => 'valid',
        ]);
    }

    /**
     * XXXX-XXXXXXXX, from a CSPRNG, checked for collision.
     *
     * The unique index on tickets.code is the real guarantee; this loop keeps
     * an astronomically unlikely collision from surfacing as a failed purchase.
     */
    private function code(): string
    {
        do {
            $code = $this->segment(4).'-'.$this->segment(8);
        } while (Ticket::where('code', $code)->exists());

        return $code;
    }

    private function segment(int $length): string
    {
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
