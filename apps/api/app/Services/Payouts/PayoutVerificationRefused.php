<?php

namespace App\Services\Payouts;

use RuntimeException;

/** A verification that will not be recorded, with the sentence an operator needs. */
class PayoutVerificationRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
