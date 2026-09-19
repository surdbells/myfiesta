<?php

namespace App\Services\Disputes;

use App\Contracts\Payments\PaymentEvent;
use Carbon\CarbonImmutable;

/**
 * The dispute itself, dug out of whichever processor's shape it arrived in.
 *
 * PaymentEvent deliberately says only what the domain needs of any payment —
 * type, reference, amount — so the parts that are specific to a dispute are
 * read from the raw payload here rather than widening that contract for one
 * case. A field the processor did not send is null, and the caller falls back
 * to what the order already says.
 */
final readonly class Details
{
    public function __construct(
        public string $reference,
        public int $amount,
        public string $currency,
        public ?string $reason,
        public ?CarbonImmutable $evidenceDueAt,
    ) {}

    public static function from(PaymentEvent $event): self
    {
        $raw = $event->raw;

        // Stripe: the dispute is the event's own object.
        $stripe = $raw['data']['object'] ?? [];
        // Paystack: a dispute sits under data, with the transaction beside it.
        $paystack = $raw['data'] ?? [];

        $reference = $stripe['id'] ?? $paystack['id'] ?? $event->reference;

        $due = $stripe['evidence_details']['due_by'] ?? null;
        $dueAt = $due
            ? CarbonImmutable::createFromTimestamp((int) $due)
            : (isset($paystack['due_at']) ? CarbonImmutable::parse($paystack['due_at']) : null);

        return new self(
            reference: (string) $reference,
            amount: (int) ($stripe['amount'] ?? $paystack['refund_amount'] ?? $event->amountMinorUnits),
            currency: strtoupper((string) ($stripe['currency'] ?? $paystack['currency'] ?? $event->currency)),
            // Stripe names a card-network reason ("product_not_received");
            // Paystack gives the buyer's own words under category.
            reason: $stripe['reason'] ?? $paystack['category'] ?? null,
            evidenceDueAt: $dueAt,
        );
    }
}
