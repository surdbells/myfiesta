<?php

namespace App\Contracts\Payments;

/**
 * What a processor keeps about one payment, in the fields that answer a dispute.
 *
 * The facts are the processor's own words, in its own vocabulary — Stripe's
 * "authenticated", Paystack's "channel" — rather than translated into one
 * shape for both. They are shown to a bank as the processor's record, and a
 * translation would be ours.
 */
final readonly class PaymentRecord
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        /** What the processor calls the payment it described. */
        public string $reference,

        public array $facts,

        /** Where the processor sent its receipt, when it says. */
        public ?string $receiptEmail = null,

        /**
         * How it was paid, in the processor's word (Stripe's card, klarna,
         * affirm), when it says.
         */
        public ?string $methodType = null,

        /**
         * What the processor took for it, in minor units of the order's
         * currency, when it says. The published rate stands in otherwise.
         */
        public ?int $fee = null,
    ) {}
}
