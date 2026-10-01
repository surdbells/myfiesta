<?php

namespace App\Services\Tickets;

use RuntimeException;

/**
 * Why a ticket cannot be sent to somebody else, in words its holder can read.
 *
 * The reason travels with the sentence, because two people read these: the
 * holder sending their own ticket, and somebody at the admin panel moving it
 * for them (TicketActions), who is told the same thing in staff words.
 */
class TicketHandoverRefused extends RuntimeException
{
    /** Already scanned at the door. */
    public const USED = 'used';

    /** Some of the people a table ticket admits are already inside. */
    public const PARTLY_USED = 'partly_used';

    /** Given back and waiting for somebody to take the place. */
    public const LISTED = 'listed';

    /** Refunded, voided or otherwise no longer admitting anybody. */
    public const GONE = 'gone';

    /** The night has started, and a door may be working from an older list. */
    public const STARTED = 'started';

    /** Sent to the address that already holds it. */
    public const SAME_ADDRESS = 'same_address';

    /**
     * No longer with whoever asked: it moved between their asking and the
     * lock, most often to somebody they sent it to a moment before.
     */
    public const MOVED = 'moved';

    public int $status = 422;

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
