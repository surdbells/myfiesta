<?php

namespace App\Http\Resources\Extensions;

use App\Services\Checkout\Quote;

/**
 * What the features added since put on a quote (CheckoutController).
 *
 * As EventExtras does for an event: one field each, from a class of the
 * feature's own, always present, so neither paying later nor a friend's
 * discount edits the controller. Which code the quote names as applied is
 * here too, because a friend's link applies one the buyer never typed.
 */
class QuoteExtras
{
    public function __construct(
        private readonly PayLater $payLater,
        private readonly Share $share,
    ) {}

    /** @return array<string, mixed> */
    public function for(Quote $quote): array
    {
        return [
            'code_applied' => $this->share->codeShown($quote),
            'pay_later' => $this->payLater->forQuote($quote),
            'friend_discount' => $this->share->forQuote($quote),
        ];
    }
}
