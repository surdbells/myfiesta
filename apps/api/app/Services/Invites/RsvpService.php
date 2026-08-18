<?php

namespace App\Services\Invites;

use App\Exceptions\CheckoutException;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\RsvpAnswer;
use App\Models\RsvpQuestion;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\TicketIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Answering an invitation.
 *
 * This is where treating Invites+ as a module rather than a product pays for
 * itself: accepting mints ordinary tickets, so the door scanner, the scan log,
 * and duplicate-entry protection all work at a wedding without a line of new
 * code. Declining voids any previously issued ones.
 *
 * A guest may answer more than once — people change their minds, and their
 * party size changes with them. Each answer supersedes the last rather than
 * overwriting it, and the tickets are reconciled to whatever the current answer
 * says.
 */
class RsvpService
{
    public function __construct(private readonly TicketIssuer $issuer) {}

    /**
     * @param  array<string, mixed>  $answers  question id => value
     */
    public function respond(
        Guest $guest,
        string $status,
        int $partySize = 1,
        array $answers = [],
        ?string $message = null,
    ): Rsvp {
        $event = $guest->event;

        if ($event->kind !== 'invitation') {
            throw new CheckoutException('This event does not take RSVPs.');
        }

        if ($partySize > $guest->max_party_size) {
            // The host decided how many this invitation admits. A guest who
            // needs more has to ask, not simply type a bigger number.
            throw new CheckoutException(
                $guest->max_party_size === 1
                    ? 'This invitation admits one person.'
                    : "This invitation admits up to {$guest->max_party_size} people."
            );
        }

        if ($status === 'declined') {
            $partySize = 0;
        }

        $this->assertRequiredAnswered($event->id, $answers, $status);

        return DB::transaction(function () use ($guest, $event, $status, $partySize, $answers, $message) {
            // Close the previous answer before opening a new one. The partial
            // unique index permits exactly one live row per guest, so this is
            // ordering, not tidiness.
            Rsvp::where('guest_id', $guest->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            $rsvp = Rsvp::create([
                'guest_id' => $guest->id,
                'event_id' => $event->id,
                'status' => $status,
                'party_size' => $partySize,
                'message' => $message,
                'responded_at' => now(),
            ]);

            $this->storeAnswers($rsvp, $answers);
            $this->reconcileTickets($guest, $rsvp);

            return $rsvp->load('answers');
        });
    }

    /**
     * Make the issued tickets match the current answer.
     *
     * Called on every response, so a party that shrinks from four to two gives
     * two back rather than leaving the host catering for phantoms.
     */
    private function reconcileTickets(Guest $guest, Rsvp $rsvp): void
    {
        $existing = Ticket::where('event_id', $rsvp->event_id)
            ->where('owner_email', $guest->email)
            ->whereIn('status', ['valid'])
            ->orderBy('created_at')
            ->get();

        $wanted = $rsvp->isAttending() ? $rsvp->party_size : 0;

        if ($existing->count() > $wanted) {
            // Void the newest first, so a guest who has already been checked in
            // keeps the ticket they arrived on.
            $existing->reverse()
                ->take($existing->count() - $wanted)
                ->each(fn (Ticket $t) => $t->update(['status' => 'void']));

            return;
        }

        $type = $this->admissionType($rsvp->event_id);

        for ($i = $existing->count(); $i < $wanted; $i++) {
            $this->issuer->issueComp(
                eventId: $rsvp->event_id,
                ticketTypeId: $type->id,
                email: $guest->email,
                name: $guest->name,
            );
        }
    }

    /**
     * Invitation events still need one ticket type for entry to hang off.
     *
     * Created on demand rather than at event creation, so a host who never
     * turns on check-in never sees it.
     */
    private function admissionType(string $eventId): TicketType
    {
        return TicketType::firstOrCreate(
            ['event_id' => $eventId, 'name' => 'Admission'],
            ['price_amount' => 0, 'status' => 'hidden'],
        );
    }

    /** @param  array<string, mixed>  $answers */
    private function storeAnswers(Rsvp $rsvp, array $answers): void
    {
        foreach ($answers as $questionId => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            RsvpAnswer::create([
                'rsvp_id' => $rsvp->id,
                'rsvp_question_id' => $questionId,
                // Always an array in storage, so reporting does not have to
                // branch on whether a question happened to be multi-choice.
                'value' => is_array($value) ? array_values($value) : [$value],
            ]);
        }
    }

    /**
     * Required questions only bind someone who is coming.
     *
     * Demanding a dietary requirement from a guest who just declined is a way
     * to lose the decline, and an unanswered decline is worse for the host than
     * an unanswered question.
     *
     * @param  array<string, mixed>  $answers
     */
    private function assertRequiredAnswered(string $eventId, array $answers, string $status): void
    {
        if ($status !== 'attending') {
            return;
        }

        $missing = RsvpQuestion::where('event_id', $eventId)
            ->where('required', true)
            ->get()
            ->filter(fn (RsvpQuestion $q) => blank($answers[$q->id] ?? null));

        if ($missing->isNotEmpty()) {
            throw new CheckoutException(
                'Please answer: '.$missing->pluck('label')->implode(', ')
            );
        }
    }
}
