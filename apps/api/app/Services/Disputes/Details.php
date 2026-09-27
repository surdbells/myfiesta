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

        // Paystack writes the deadline as dueAt, in camel case beside the
        // snake-cased rest of the dispute (resolvedAt is the same). Read only
        // as due_at, it was never found, and every Paystack chargeback came
        // in with no date to answer it by. due_at is still read, in case the
        // spelling is ever made consistent.
        $due = $stripe['evidence_details']['due_by'] ?? null;
        $paystackDue = $paystack['dueAt'] ?? $paystack['due_at'] ?? null;
        $dueAt = $due
            ? CarbonImmutable::createFromTimestamp((int) $due)
            : (is_string($paystackDue) && $paystackDue !== '' ? CarbonImmutable::parse($paystackDue) : null);

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
