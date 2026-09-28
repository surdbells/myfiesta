<?php

namespace App\Services\Payouts;

use RuntimeException;

/** A repayment that will not be recorded, with the sentence to show. */
class RepaymentRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
