<?php

namespace App\Services\Door;

use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * @param  string|null  $clientId  The scan's own id, minted on the phone.
     *                                 Sending the same scan twice — a request
     *                                 that timed out after the server had
     *                                 already admitted the guest — returns the
     *                                 first result instead of refusing the
     *                                 guest it let in.
     */
    public function scan(
        string $code,
        string $eventId,
        ?User $scanner = null,
        ?int $party = null,
        ?string $clientId = null,
        ?string $doorPassId = null,
    ): ScanOutcome {
        return $this->perform($code, $eventId, $scanner, $party, $clientId, doorPassId: $doorPassId);
    }

    /**
     * A scan the door already acted on while it had no connection.
     *
     * The door decided from its downloaded list and somebody either walked in
     * or was turned away. That has happened; this records it. The server does
     * not get a second opinion about whether they go in — they are already in,
     * or already gone.
     *
     * So admission is applied only for scans the door accepted, and only as far
     * as the ticket truly allows. When the door let somebody in on a ticket the
     * server knows was already used — two phones working one queue offline, or
     * a screenshot passed down the line — nothing is double counted and the
     * disagreement is kept on the row for the organizer to see. A scan the door
     * refused is recorded with the server's verdict and admits nobody, even if
     * the ticket turns out to have been fine: that guest was turned away, and
     * marking the ticket used would refuse them again when they come back.
     */
    public function recordOffline(
        string $code,
        string $eventId,
        ?User $scanner,
        ?int $party,
        string $clientId,
        string $offlineResult,
        CarbonInterface $scannedAt,
        ?string $doorPassId = null,
    ): ScanOutcome {
        return $this->perform(
            $code,
            $eventId,
            $scanner,
            $party,
            $clientId,
            $offlineResult,
            $this->believable($scannedAt),
            $doorPassId,
        );
    }

    private function perform(
        string $code,
        string $eventId,
        ?User $scanner,
        ?int $party,
        ?string $clientId,
        ?string $offlineResult = null,
        ?CarbonInterface $scannedAt = null,
        ?string $doorPassId = null,
    ): ScanOutcome {
        return DB::transaction(function () use ($code, $eventId, $scanner, $party, $clientId, $offlineResult, $scannedAt, $doorPassId) {
            $ticket = Ticket::query()
                ->where('code', strtoupper(trim($code)))
                ->lockForUpdate()
                ->first();

            // Checked after taking the ticket's lock, not before: two copies of
            // the same scan arriving together are for the same ticket, so the
            // second waits here and then finds the first one's row.
            if ($clientId !== null) {
                $earlier = TicketScan::query()->where('client_id', $clientId)->first();

                if ($earlier) {
                    return $this->replay($earlier, $ticket);
                }
            }

            $outcome = $this->decide($ticket, $eventId, $party);

            $admitCount = $this->admitCount($outcome, $offlineResult);

            if ($admitCount > 0) {
                $admitted = $ticket->admitted_count + $admitCount;

                $ticket->update([
                    'admitted_count' => $admitted,
                    // Only spent once the last of the party is inside. Until
                    // then it stays valid so the rest can still get in.
                    'status' => $admitted >= $ticket->admits ? 'checked_in' : 'valid',
                    'checked_in_at' => $ticket->checked_in_at ?? $scannedAt ?? now(),
                    'checked_in_by' => $ticket->checked_in_by ?? $scanner?->id,
                ]);
            }

            $row = [
                'ticket_id' => $ticket?->id,
                'event_id' => $eventId,
                'scanned_by' => $scanner?->id,
                // Which phone, when it was a door pass: the member above
                // vouched for it, the pass says which door it was.
                'door_pass_id' => $doorPassId,
                // Recorded even when it matches nothing. A door reporting
                // unknown codes all night is worth knowing about.
                'scanned_code' => substr(strtoupper(trim($code)), 0, 32),
                'result' => $outcome->result,
                'admitted' => $admitCount,
                'client_id' => $clientId,
                'offline_result' => $offlineResult,
                'scanned_at' => $scannedAt ?? now(),
            ];

            if ($ticket === null && $clientId !== null) {
                // No ticket row to lock, so two copies of an unknown code can
                // race to the unique index. ON CONFLICT DO NOTHING rather than
                // an exception: in Postgres a failed insert poisons the rest of
                // the transaction.
                TicketScan::query()->insertOrIgnore([
                    ...$row,
                    'id' => (string) Str::uuid7(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                TicketScan::create($row);
            }

            $final = $admitCount === $outcome->admitted && $outcome->admittedAnyone()
                ? $outcome
                : new ScanOutcome(
                    $outcome->result,
                    $outcome->message,
                    admitted: $admitCount,
                    remaining: $ticket ? max(0, $ticket->admits - $ticket->admitted_count) : 0,
                    applied: $admitCount > 0,
                );

            return $final->withTicket($ticket)->withOfflineResult($offlineResult);
        });
    }

    /**
     * How many people this scan puts inside, as far as the ticket counts.
     *
     * Online it is the decision. Offline it is what the door did: nobody for a
     * refusal, whatever the ticket allows for an admission. The one case that
     * needs care is a table waved in offline after another phone had already
     * let part of it in — say four in, with two places left. They are all
     * inside, so counting nobody would leave two places open for somebody else
     * later; counting four would break the ticket. Two are counted, and the
     * scan still carries the disagreement.
     */
    private function admitCount(ScanOutcome $outcome, ?string $offlineResult): int
    {
        if ($offlineResult === null) {
            return $outcome->admittedAnyone() ? $outcome->admitted : 0;
        }

        if ($offlineResult !== ScanOutcome::ACCEPTED) {
            return 0;
        }

        return match ($outcome->result) {
            ScanOutcome::ACCEPTED => $outcome->admitted,
            ScanOutcome::OVER_CAPACITY => $outcome->remaining,
            default => 0,
        };
    }

    /** The answer this scan got the first time it arrived. */
    private function replay(TicketScan $earlier, ?Ticket $ticket): ScanOutcome
    {
        $remaining = $ticket ? max(0, $ticket->admits - $ticket->admitted_count) : 0;

        return (new ScanOutcome(
            $earlier->result,
            $earlier->admitted > 0 ? 'Already recorded — they are in.' : 'Already recorded.',
            admitted: $earlier->admitted,
            remaining: $remaining,
            applied: $earlier->offline_result === null || $earlier->admitted > 0,
        ))->withTicket($ticket)->withOfflineResult($earlier->offline_result);
    }

    /**
     * When the phone says the scan happened.
     *
     * Its clock is trusted within reason, because "10:47pm" is what somebody
     * reconciling a door wants to see — not the moment a basement found signal
     * again at 1am. Outside reason, which is a phone set to the wrong year or a
     * scan claiming to come from the future, the arrival time is used instead.
     */
    private function believable(CarbonInterface $at): CarbonInterface
    {
        return $at->isAfter(now()->addMinutes(5)) || $at->isBefore(now()->subDays(3))
            ? now()
            : $at;
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
