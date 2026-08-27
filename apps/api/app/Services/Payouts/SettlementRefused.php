<?php

namespace App\Services\Payouts;

use RuntimeException;

/**
 * A payout that will not be recorded, with the reason an operator needs.
 *
 * Carries a sentence rather than a code because there is exactly one audience:
 * the person standing at the admin panel who has just moved real money and
 * needs to know why the record of it was refused.
 */
class SettlementRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
