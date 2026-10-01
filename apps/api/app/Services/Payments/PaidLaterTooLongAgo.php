<?php

namespace App\Services\Payments;

use App\Services\Refunds\RefundRefused;

/**
 * A refund refused because the order was paid with Klarna or Affirm longer
 * ago than the lender takes money back for (PayLater::refundRefusal).
 *
 * A refusal like any other to whoever asked, with its own type so the places
 * that sweep many refunds at once can tell it apart from "nothing left to
 * refund": this one is money still owed, which support returns another way
 * and then records (RefundService::recordMadeElsewhere).
 */
class PaidLaterTooLongAgo extends RefundRefused
{
    public static function saying(string $message): self
    {
        return new self($message);
    }
}
