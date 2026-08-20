<?php

namespace App\Services\Door;

use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admission at the door.
 *
 * Admission is a count, not a flag. A Couple ticket admits two and a Table of 5
 * admits five, and those parties do not reliably arrive together — three at
 * eleven, two at midnight is an ordinary evening. A binary checked-in flag
 * consumes the whole ticket on the first scan and turns the rest of the table
 * away holding a ticket the system says is spent.
 *
 * So each scan admits some number of people and the ticket closes when the last
 * of them is inside.
 *
 * The row is locked for the whole decision. Two scanners on the same table at
 * the same moment is exactly the situation this exists for, and the database
 * carries a matching constraint so a race cannot put six through a table of
 * five even if this logic were wrong.
 */
class CheckInService
{
    /**
     * @param  int|null  $party  How many are going in now. Null admits everyone
     *                           still outstanding, which is the common case and
     *                           the right default for a single-admission ticket.
     */
    public function scan(
        string $code,
        string $eventId,
        ?User $scanner = null,
        ?int $party = null,
    ): ScanOutcome {
        return DB::transaction(function () use ($code, $eventId, $scanner, $party) {
            $ticket = Ticket::query()
                ->where('code', strtoupper(trim($code)))
                ->lockForUpdate()
                ->first();

            $outcome = $this->decide($ticket, $eventId, $party);

            if ($outcome->admittedAnyone()) {
                $admitted = $ticket->admitted_count + $outcome->admitted;

                $ticket->update([
                    'admitted_count' => $admitted,
                    // Only spent once the last of the party is inside. Until
                    // then it stays valid so the rest can still get in.
                    'status' => $admitted >= $ticket->admits ? 'checked_in' : 'valid',
                    'checked_in_at' => $ticket->checked_in_at ?? now(),
                    'checked_in_by' => $ticket->checked_in_by ?? $scanner?->id,
                ]);
            }

            TicketScan::create([
                'ticket_id' => $ticket?->id,
                'event_id' => $eventId,
                'scanned_by' => $scanner?->id,
                // Recorded even when it matches nothing. A door reporting
                // unknown codes all night is worth knowing about.
                'scanned_code' => substr(strtoupper(trim($code)), 0, 32),
                'result' => $outcome->result,
                'admitted' => $outcome->admitted,
                'scanned_at' => now(),
            ]);

            return $outcome->withTicket($ticket);
        });
    }

    private function decide(?Ticket $ticket, string $eventId, ?int $party): ScanOutcome
    {
        if ($ticket === null) {
            return new ScanOutcome(ScanOutcome::NOT_FOUND, 'Not recognised.');
        }

        // Checked before validity: a real ticket for the wrong night is a
        // different conversation from a forged one, and the person on the door
        // needs to know which they are having.
        if ($ticket->event_id !== $eventId) {
            return new ScanOutcome(ScanOutcome::WRONG_EVENT, 'Valid ticket, different event.');
        }

        if (in_array($ticket->status, ['refunded', 'void'], true)) {
            return new ScanOutcome(ScanOutcome::VOID, 'This ticket was cancelled.');
        }

        $remaining = $ticket->admits - $ticket->admitted_count;

        if ($remaining <= 0) {
            $when = $ticket->checked_in_at?->diffForHumans();

            return new ScanOutcome(
                ScanOutcome::DUPLICATE,
                $ticket->admits === 1
                    ? ($when ? "Already scanned {$when}." : 'Already scanned.')
                    : "All {$ticket->admits} already came in".($when ? " — first {$when}." : '.'),
            );
        }

        // Default to the whole remaining party. For an ordinary single ticket
        // that is one person and the door never has to think about it.
        $wanted = $party ?? $remaining;

        if ($wanted < 1) {
            return new ScanOutcome(ScanOutcome::OVER_CAPACITY, 'Admit at least one person.');
        }

        if ($wanted > $remaining) {
            // Refused rather than clamped. Somebody presenting four against a
            // table with two left is a conversation to have at the door, not a
            // number to quietly round down.
            return new ScanOutcome(
                ScanOutcome::OVER_CAPACITY,
                $remaining === 1
                    ? 'Only 1 place left on this ticket.'
                    : "Only {$remaining} places left on this ticket.",
                remaining: $remaining,
            );
        }

        $left = $remaining - $wanted;

        return new ScanOutcome(
            ScanOutcome::ACCEPTED,
            $this->admittedMessage($ticket->admits, $wanted, $left),
            admitted: $wanted,
            remaining: $left,
        );
    }

    /**
     * What the door reads back.
     *
     * The number still to come matters more than the number admitted — it is
     * what tells whoever is holding the phone whether to keep the ticket open.
     */
    private function admittedMessage(int $admits, int $admitted, int $left): string
    {
        if ($admits === 1) {
            return 'Admitted.';
        }

        if ($left === 0) {
            return $admitted === $admits
                ? "Admitted all {$admits}."
                : "Admitted {$admitted}. That is everyone.";
        }

        return "Admitted {$admitted}. {$left} still to come.";
    }
}
