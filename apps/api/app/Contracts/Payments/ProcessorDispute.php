<?php

namespace App\Contracts\Payments;

use Carbon\CarbonImmutable;

/**
 * What a processor says about a dispute, read from its API.
 *
 * The facts are in the processor's own vocabulary, like PaymentRecord's, and
 * reduced to what staff need to answer it: never the card's first six digits,
 * which Paystack sends with every dispute, and never anything that would let
 * the card be charged again.
 */
final readonly class ProcessorDispute
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $enhancedEligibility  Stripe's enhanced_eligibility_types, e.g. visa_compelling_evidence_3
     */
    public function __construct(
        public string $reference,
        /** The processor's own word for where it is: needs_response, under_review, won, lost, resolved… */
        public ?string $status,
        public ?string $reason,
        public ?string $networkReasonCode,
        public ?int $amount,
        public ?string $currency,
        public ?CarbonImmutable $dueAt,
        public array $facts = [],
        public array $enhancedEligibility = [],
    ) {}
}
