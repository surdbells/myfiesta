<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\Ticket;
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
        // An unclaimed account, so the buyer's tickets are already waiting if
        // they later register with the same address. Creating it now is free;
        // reconstructing the link afterwards means matching on email anyway.
        $owner = $order->user ?? User::firstOrCreate(
            ['email' => $order->buyer_email],
            ['name' => $order->buyer_name, 'password' => null],
        );

        $tickets = [];

        foreach ($order->lines as $line) {
            for ($i = 0; $i < $line->quantity; $i++) {
                $tickets[] = Ticket::create([
                    'code' => $this->code(),
                    'event_id' => $order->event_id,
                    'ticket_type_id' => $line->ticket_type_id,
                    'order_id' => $order->id,
                    'owner_user_id' => $owner->id,
                    'owner_email' => $order->buyer_email,
                    'holder_name' => $order->buyer_name,
                    'status' => 'valid',
                ]);
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
        $owner = User::firstOrCreate(
            ['email' => strtolower(trim($email))],
            ['name' => $name, 'password' => null],
        );

        return Ticket::create([
            'code' => $this->code(),
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
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
