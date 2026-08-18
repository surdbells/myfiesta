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

    public ?Ticket $ticket = null;

    public function __construct(
        public readonly string $result,
        public readonly string $message,
    ) {}

    public function withTicket(?Ticket $ticket): self
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function admitted(): bool
    {
        return $this->result === self::ACCEPTED;
    }
}
