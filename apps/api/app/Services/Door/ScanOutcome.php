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

    public ?Ticket $ticket = null;

    public function __construct(
        public readonly string $result,
        public readonly string $message,
        /** How many people this scan let in. Zero on every refusal. */
        public readonly int $admitted = 0,
        /** How many of the party are still outside afterwards. */
        public readonly int $remaining = 0,
    ) {}

    public function withTicket(?Ticket $ticket): self
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function admittedAnyone(): bool
    {
        return $this->result === self::ACCEPTED;
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
}
