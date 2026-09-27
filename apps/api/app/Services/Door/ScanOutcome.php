<?php

namespace App\Services\Door;

use App\Models\Ticket;

/**
 * What happened, in terms the person on the door can act on.
 */
class ScanOutcome
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const NOT_FOUND = 'not_found';

    public const WRONG_EVENT = 'wrong_event';

    public const VOID = 'void';

    /** Asked for more people than the ticket has left. */
    public const OVER_CAPACITY = 'over_capacity';

    /**
     * A ticket with more than one person still to come, scanned without
     * saying how many are here. Not a verdict but a question: nobody went in,
     * nobody was turned away, and the door is to ask and scan it again with
     * the number. See CheckInService::decide.
     */
    public const CHOOSE_PARTY = 'choose_party';

    /** The door let somebody in offline on a ticket the server refused. */
    public const CONFLICT_ADMITTED_INVALID = 'admitted_invalid';

    /** The door turned somebody away offline on a ticket that was valid. */
    public const CONFLICT_REFUSED_VALID = 'refused_valid';

    public ?Ticket $ticket = null;

    /** What the door decided with no connection, when it had none. */
    public ?string $offlineResult = null;

    public function __construct(
        public readonly string $result,
        public readonly string $message,
        /** How many people this scan let in. Zero on every refusal. */
        public readonly int $admitted = 0,
        /** How many of the party are still outside afterwards. */
        public readonly int $remaining = 0,
        /**
         * Whether the verdict was acted on. False for an offline refusal the
         * server would have accepted: the result says the ticket was fine,
         * and nobody went in.
         */
        public readonly bool $applied = true,
    ) {}

    public function withTicket(?Ticket $ticket): self
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function withOfflineResult(?string $offlineResult): self
    {
        $this->offlineResult = $offlineResult;

        return $this;
    }

    public function admittedAnyone(): bool
    {
        return $this->result === self::ACCEPTED && $this->applied;
    }

    /** Whether the door has to say how many of the party are here before anybody goes in. */
    public function asksHowMany(): bool
    {
        return $this->result === self::CHOOSE_PARTY;
    }

    /**
     * Whether the ticket still has people to come.
     *
     * The difference between "let them in, three more to come" and "that is
     * everyone" — which is what the door needs to know before handing the phone
     * back.
     */
    public function partiallyAdmitted(): bool
    {
        return $this->admittedAnyone() && $this->remaining > 0;
    }

    /**
     * Where the door, working offline, and the server disagree.
     *
     * Null online, and null offline when they agree. The two disagreements
     * are not equal: somebody let in on a spent ticket is a loss to look into,
     * somebody turned away on a good one is a guest to apologise to.
     */
    public function conflict(): ?string
    {
        if ($this->offlineResult === null) {
            return null;
        }

        $doorAdmitted = $this->offlineResult === self::ACCEPTED;
        $serverAdmits = $this->result === self::ACCEPTED;

        return match (true) {
            $doorAdmitted && ! $serverAdmits => self::CONFLICT_ADMITTED_INVALID,
            ! $doorAdmitted && $serverAdmits => self::CONFLICT_REFUSED_VALID,
            default => null,
        };
    }
}
