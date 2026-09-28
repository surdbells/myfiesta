<?php

namespace App\Services\Payouts;

use RuntimeException;

/**
 * An organization that cannot be closed while it owes myFiesta money.
 *
 * Carries the sentence that says how much, in which currency, and what has to
 * happen first.
 */
class OverdraftOutstanding extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
