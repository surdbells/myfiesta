<?php

namespace App\Services\Refunds;

use RuntimeException;

/**
 * A refund the domain will not perform.
 *
 * Separate from a gateway failure on purpose. This is the platform declining —
 * the order is not paid, the tickets are already refunded, the amount exceeds
 * what is left — and every message it carries is written to be shown to an
 * organizer as-is. A gateway failure is somebody else's sentence and gets
 * logged rather than repeated.
 */
class RefundRefused extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
