<?php

namespace App\Services\Door;

use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admission at the door.
 *
 * The previous endpoint set the checked-in flag and then decided, from the row
 * it had read beforehand, whether to report the ticket as already scanned. Two
 * scanners hitting the same code concurrently could both be told it was valid.
 * There was no transaction, no scan log, and no record of a rejection.
 *
 * Here the ticket row is locked, the decision is made from the locked state,
 * and every attempt is recorded — including the ones that fail. A scanner that
 * only logs successes cannot answer what happened at a contested door, which is
 * exactly when someone asks.
 */
class CheckInService
{
    public function scan(string $code, string $eventId, ?User $scanner = null): ScanOutcome
    {
        return DB::transaction(function () use ($code, $eventId, $scanner) {
            $ticket = Ticket::query()
                ->where('code', strtoupper(trim($code)))
                ->lockForUpdate()
                ->first();

            $outcome = $this->decide($ticket, $eventId);

            if ($outcome->result === ScanOutcome::ACCEPTED) {
                $ticket->update([
                    'status' => 'checked_in',
                    'checked_in_at' => now(),
                    'checked_in_by' => $scanner?->id,
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
                'scanned_at' => now(),
            ]);

            return $outcome->withTicket($ticket);
        });
    }

    private function decide(?Ticket $ticket, string $eventId): ScanOutcome
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

        if ($ticket->status === 'checked_in') {
            $when = $ticket->checked_in_at?->diffForHumans();

            return new ScanOutcome(
                ScanOutcome::DUPLICATE,
                $when ? "Already scanned {$when}." : 'Already scanned.',
            );
        }

        return new ScanOutcome(ScanOutcome::ACCEPTED, 'Admitted.');
    }
}
