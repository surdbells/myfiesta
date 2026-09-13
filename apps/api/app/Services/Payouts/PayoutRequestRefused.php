<?php

namespace App\Services\Payouts;

use RuntimeException;

/** A payout request that cannot be made or decided, with the sentence to show. */
class PayoutRequestRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
