<?php

namespace App\Services\Door;

use App\Events\TicketAdmitted;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\User;
use App\Services\Integrations\Payloads;
use App\Services\Integrations\Webhooks;
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
 * And the number is said, never assumed. A scan that did not say how many used
 * to let in everyone still outstanding, so a table's ticket held up by the
 * first of its guests admitted the whole table, and the rest of it walked in
 * later past a door that had already counted them. Now a scan with no number,
 * on a ticket with more than one person still to come, lets nobody in and
 * asks (`choose_party`); the door answers with how many are standing there,
 * all of them or some. The last place on a ticket needs no question.
 *
 * The row is locked for the whole decision. Two scanners on the same table at
 * the same moment is exactly the situation this exists for, and the database
 * carries a matching constraint so a race cannot put six through a table of
 * five even if this logic were wrong.
 */
class CheckInService
{
    /**
     * @param  int|null  $party  How many are going in now. Null is the door not
     *                           saying: the one place left if that is all there
     *                           is, which covers every single-admission ticket,
     *                           and otherwise nobody yet — the answer is
     *                           `choose_party`, and the door asks how many are
     *                           here. Nothing is recorded for that answer: it is
     *                           a question, and the scan is the one that
     *                           follows it with a number.
     * @param  string|null  $clientId  The scan's own id, minted on the phone.
     *                                 Sending the same scan twice — a request
     *                                 that timed out after the server had
     *                                 already admitted the guest — returns the
     *                                 first result instead of refusing the
     *                                 guest it let in. The scan answering
     *                                 `choose_party` is sent under the id of
     *                                 the one that asked: nothing is recorded
     *                                 for a question, so the id is free, and a
     *                                 question the phone put from its own list
     *                                 after a timeout may be about a scan this
     *                                 server had already let in.
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
     *
     * The same holds for a scan the server had already answered online, when
     * that answer never reached the door and the scan came back in the queue:
     * it is still one scan and one row, but what the door did with it is kept
     * and compared all the same. See `reconcile`.
     *
     * A party of null keeps the meaning it had when the door acted on it:
     * everyone still outstanding. This is not a question to ask, because the
     * door has already answered it — a phone from before doors asked how many
     * are here, working with no signal, let the whole of what its list had
     * left through on one scan, and those people are inside. Doors that ask
     * send the number they let in on any ticket for more than one, so a null
     * from them only ever meets a single place.
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
                    return $offlineResult !== null && $this->awaitsDoorsAnswer($earlier, $eventId, $code)
                        ? $this->reconcile($earlier, $ticket, $eventId, $scanner, $party, $offlineResult)
                        : $this->replay($earlier, $ticket, $offlineResult === null ? $party : null);
                }
            }

            // Asked only of a door that is standing in front of the guest. One
            // syncing what it did with no signal has already done it.
            $outcome = $this->decide($ticket, $eventId, $party, askHowMany: $offlineResult === null);

            // Nobody went in and nobody was turned away, so there is no scan
            // to record yet: the one that says how many is. Recorded, it would
            // read as a refusal in every count of the night's refusals.
            if ($outcome->asksHowMany()) {
                return $outcome->withTicket($ticket);
            }

            $admitCount = $this->admitCount($outcome, $offlineResult);

            if ($admitCount > 0) {
                $this->admit(
                    $ticket,
                    $admitCount,
                    $eventId,
                    $scanner,
                    $scannedAt,
                    $offlineResult === null ? TicketAdmitted::ONLINE : TicketAdmitted::OFFLINE_SYNC,
                );
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

    /**
     * Count people in against a ticket, and say so to anyone listening.
     *
     * @param  TicketAdmitted::ONLINE|TicketAdmitted::OFFLINE_SYNC  $source
     */
    private function admit(
        Ticket $ticket,
        int $count,
        string $eventId,
        ?User $scanner,
        ?CarbonInterface $scannedAt,
        string $source,
    ): void {
        $admitted = $ticket->admitted_count + $count;

        $ticket->update([
            'admitted_count' => $admitted,
            // Only spent once the last of the party is inside. Until then it
            // stays valid so the rest can still get in.
            'status' => $admitted >= $ticket->admits ? 'checked_in' : 'valid',
            'checked_in_at' => $ticket->checked_in_at ?? $scannedAt ?? now(),
            'checked_in_by' => $ticket->checked_in_by ?? $scanner?->id,
        ]);

        // Somebody walked in. For a live screen elsewhere — a bar that wants
        // to know the room is filling, a promoter watching their list arrive.
        // The person, never their code.
        // Deleted or not: a night taken out of every list still had a door.
        $event = Event::withTrashed()->findOrFail($ticket->event_id);

        app(Webhooks::class)->emit(
            $event->organization_id,
            'ticket.checked_in',
            [
                ...app(Payloads::class)->attendee($ticket),
                'admitted_now' => $count,
                'event_id' => $eventId,
            ],
        );

        // And the platform's own features, once the admission has
        // committed: only here, where somebody was actually counted in, so
        // a refusal, a repeat of a scan or a question about how many never
        // says it.
        TicketAdmitted::dispatch($ticket, $event, $count, $source);
    }

    /**
     * Whether an offline scan is the queued copy of one the server decided
     * online, and has not yet been told what the door did.
     *
     * The phone sends a scan, hears nothing back, decides from its saved list
     * and queues the scan under the same id. When the request had in fact
     * arrived, the server holds an answer nobody at the door ever saw. Only
     * once, though: after the door's answer has been kept, the next copy is a
     * sync sent again, and gets a plain replay.
     *
     * Same event and same code as well as the same id, because a copy is the
     * same scan. Anything else is not a copy, and is answered the way it
     * always was rather than being allowed to rewrite somebody else's row.
     */
    private function awaitsDoorsAnswer(TicketScan $earlier, string $eventId, string $code): bool
    {
        return $earlier->offline_result === null
            && $earlier->event_id === $eventId
            && $earlier->scanned_code === substr(strtoupper(trim($code)), 0, 32);
    }

    /**
     * What the door did with a scan the server had already answered online.
     *
     * The door never saw the online answer, so what happened in the doorway is
     * the door's decision. That is what is kept, on the row the scan already
     * has, and judged by the same rules as any offline scan: a guest let in on
     * a ticket the server refused is a conflict for the organizer, not a quiet
     * "refused, nobody in".
     *
     * The door's decision is judged against the ticket as it stood without
     * this scan's own online admission, as if the scan had only ever come
     * through the sync. Then only people the door let in beyond what the
     * online answer already counted are added, and only as far as the ticket
     * allows, so nobody is counted twice.
     *
     * Nobody is taken back off, either. A door that turned away a guest the
     * server had just let in is told so and the ticket keeps reading as used:
     * that guest is owed an apology and a way in, which the door can give
     * them, and an admission quietly withdrawn from the record is harder to
     * reconcile afterwards than one that is flagged.
     *
     * Unless the door turned them away because it had already let somebody
     * else in on that ticket with no signal. Then the door was right, and the
     * place the server gave this scan is the one that somebody is standing
     * in. See `spentOffline`.
     */
    private function reconcile(
        TicketScan $earlier,
        ?Ticket $ticket,
        string $eventId,
        ?User $scanner,
        ?int $party,
        string $offlineResult,
    ): ScanOutcome {
        $counted = (int) $earlier->admitted;
        $doorAdmitted = $offlineResult === ScanOutcome::ACCEPTED;

        $spent = ! $doorAdmitted && $counted > 0
            ? $this->spentOffline($earlier, $ticket, $eventId, $party)
            : null;

        if ($spent !== null) {
            // Recorded as the refusal it was, and not as a guest to fetch back
            // in. The ticket keeps its count: those places are taken, by the
            // people the door let in first, whose own scans carry the conflict.
            $earlier->update([
                'result' => $spent->result,
                'admitted' => 0,
                'offline_result' => $offlineResult,
            ]);

            $said = 'Turned away with no signal, and rightly: somebody else had already been let in on this ticket with no signal.';

            return (new ScanOutcome(
                $spent->result,
                $spent->result === ScanOutcome::DUPLICATE
                    ? "{$said} Nobody else goes in on it."
                    : "{$said} {$spent->message}",
                remaining: $ticket ? max(0, $ticket->admits - $ticket->admitted_count) : 0,
                applied: false,
            ))->withTicket($ticket)->withOfflineResult($offlineResult);
        }

        // The same decision both ways — a retry, not a disagreement — or a
        // refusal by the door, which adds nobody. Either way the door's answer
        // is written beside the server's and the row speaks for itself: the
        // second is a conflict by the ordinary rule, refused beside accepted.
        if (! $doorAdmitted || ($counted > 0 && ($party === null || $party <= $counted))) {
            $earlier->update(['offline_result' => $offlineResult]);

            return $this->replay($earlier, $ticket);
        }

        $outcome = $this->decide($ticket, $eventId, $party, alreadyCounted: $counted);
        $more = max(0, $this->admitCount($outcome, $offlineResult) - $counted);

        if ($more > 0) {
            // What the door did with no signal, arriving in its sync.
            $this->admit($ticket, $more, $eventId, $scanner, $earlier->scanned_at, TicketAdmitted::OFFLINE_SYNC);
        }

        // The verdict on what the door did, rather than on what the online
        // request asked, so the row reads exactly as an offline conflict does
        // and every report that looks for those finds it.
        $earlier->update([
            'result' => $outcome->result,
            'admitted' => $counted + $more,
            'offline_result' => $offlineResult,
        ]);

        return (new ScanOutcome(
            $outcome->result,
            $outcome->message,
            admitted: $counted + $more,
            remaining: $ticket ? max(0, $ticket->admits - $ticket->admitted_count) : 0,
            applied: $counted + $more > 0,
        ))->withTicket($ticket)->withOfflineResult($offlineResult);
    }

    /**
     * Whether a door that turned away a scan the server had let in online did
     * so because it had already let somebody else in on the ticket with no
     * signal — and if so, what the server says of the ticket now.
     *
     * The phone marks a ticket used on its list the moment it lets somebody
     * in with no signal. If signal comes back before that admission has been
     * sent, the next person showing the same ticket — a screenshot passed down
     * a line — goes to the server first. The server has not heard of the first
     * guest and lets them in; the answer is lost; the list says used; the door
     * turns them away. When the first admission arrives the ticket is already
     * counted, so it is flagged. Telling the door the second guest "can come
     * in" as well would put two people in on one ticket, with no scan saying
     * so for the second.
     *
     * So: somebody on this ticket was let in with no signal and could not be
     * counted — the same question the offline report asks — and the ticket,
     * counted as it now is, has no room for this party. Null otherwise, and
     * the refusal is judged by the ordinary rule.
     */
    private function spentOffline(TicketScan $earlier, ?Ticket $ticket, string $eventId, ?int $party): ?ScanOutcome
    {
        if ($ticket === null) {
            return null;
        }

        $uncounted = TicketScan::query()
            ->where('ticket_id', $ticket->id)
            ->whereKeyNot($earlier->getKey())
            ->where('offline_result', ScanOutcome::ACCEPTED)
            ->where('result', '<>', ScanOutcome::ACCEPTED)
            ->exists();

        if (! $uncounted) {
            return null;
        }

        $verdict = $this->decide($ticket, $eventId, $party);

        return $verdict->result === ScanOutcome::ACCEPTED ? null : $verdict;
    }

    /**
     * The answer this scan got the first time it arrived.
     *
     * @param  int|null  $asked  How many the door says are here now, when the
     *                           scan comes back online with a number. More than
     *                           the scan let in only when it answers a question
     *                           the phone put from its own list after a
     *                           timeout, about a scan this server had already
     *                           let in on the one place left. The one is in;
     *                           the rest of those standing there are said to
     *                           have no place, rather than all of them being
     *                           told they are in.
     */
    private function replay(TicketScan $earlier, ?Ticket $ticket, ?int $asked = null): ScanOutcome
    {
        $remaining = $ticket ? max(0, $ticket->admits - $ticket->admitted_count) : 0;
        $counted = (int) $earlier->admitted;
        $uncounted = $asked !== null && $counted > 0 ? max(0, $asked - $counted) : 0;

        // Counted in online while the door, with no answer, turned them away.
        // "They are in" would be the one thing not true.
        $turnedAway = $earlier->offline_result !== null && $earlier->offline_result !== ScanOutcome::ACCEPTED;

        return (new ScanOutcome(
            $earlier->result,
            match (true) {
                $counted > 0 && $turnedAway => 'Turned away with no signal, but the server had already let them in, so the ticket reads as used. They can come in.',
                $uncounted > 0 => "Already recorded for {$counted} of them, who can come in. "
                    .($remaining === 0
                        ? "The ticket has no places left for the other {$uncounted}."
                        : "The other {$uncounted} need a scan of their own."),
                $counted > 0 => 'Already recorded — they are in.',
                default => 'Already recorded.',
            },
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

    /**
     * @param  int  $alreadyCounted  People this same scan already put on the
     *                               ticket, online, before the door's own
     *                               answer arrived. Not held against it — see
     *                               `reconcile`.
     * @param  bool  $askHowMany  Whether a scan with no party, on a ticket
     *                            with more than one place left, is answered
     *                            with a question rather than with everyone.
     *                            Online only — see `recordOffline`.
     */
    private function decide(
        ?Ticket $ticket,
        string $eventId,
        ?int $party,
        int $alreadyCounted = 0,
        bool $askHowMany = false,
    ): ScanOutcome {
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

        // Given back and waiting for somebody else. Named rather than lumped
        // in with cancelled: the person at the door may have listed it by
        // accident and can take it back off the list from their email.
        if ($ticket->status === 'listed') {
            return new ScanOutcome(ScanOutcome::VOID, 'This ticket was handed back and is waiting to be resold.');
        }

        $remaining = $ticket->admits - $ticket->admitted_count + $alreadyCounted;

        if ($remaining <= 0) {
            $when = $ticket->checked_in_at?->diffForHumans();

            return new ScanOutcome(
                ScanOutcome::DUPLICATE,
                $ticket->admits === 1
                    ? ($when ? "Already scanned {$when}." : 'Already scanned.')
                    : "All {$ticket->admits} already came in".($when ? " — first {$when}." : '.'),
            );
        }

        // A table's ticket is shown by whoever of the table is at the front,
        // and letting in everyone it has left would wave the rest through
        // whenever they turn up, unscanned and already counted. So the door
        // is asked. The checks above come first, so a spent, cancelled or
        // wrong-night ticket is refused at once rather than asked about.
        if ($party === null && $remaining > 1 && $askHowMany) {
            return new ScanOutcome(
                ScanOutcome::CHOOSE_PARTY,
                $this->howManyMessage($ticket->admits, $remaining),
                remaining: $remaining,
            );
        }

        // No number and one place left, which is every ordinary single ticket
        // and the last of a table: that one person, and the door never has to
        // think about it. Or a sync from a door that let everyone through.
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

    /**
     * The question, in words that work on a door that cannot show buttons for
     * it: a phone app from before doors asked puts this under "Do not admit",
     * and its "How many" box is how that door answers.
     */
    private function howManyMessage(int $admits, int $remaining): string
    {
        $in = $admits - $remaining;

        return "This ticket admits {$admits}, ".($in === 0 ? 'nobody in yet' : "{$in} already in")
            .'. Put how many are going in now in How many, and scan it again.';
    }
}
