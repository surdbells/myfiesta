<?php

namespace App\Contracts\Payments;

/**
 * A verified webhook, expressed in the domain's terms rather than a processor's.
 *
 * Only ever constructed after a signature check. An instance of this class is a
 * claim that the processor said something, so building one from an unverified
 * payload defeats the entire arrangement.
 */
final readonly class PaymentEvent
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const DISPUTED = 'disputed';

    public function __construct(
        /** One of the constants above. */
        public string $type,

        /** The processor's reference, matched against orders.gateway_reference. */
        public string $reference,

        /**
         * What the processor says was actually charged, in minor units.
         *
         * Reconciled against the order total rather than trusted: a mismatch
         * means something is wrong and the order must not be fulfilled on the
         * strength of the event alone.
         */
        public int $amountMinorUnits,

        public string $currency,

        /** The processor's own event id, for idempotent webhook handling. */
        public string $eventId,

        public array $raw = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->type === self::PAID;
    }

    /**
     * Whether the processor's figures match what we expected to charge.
     *
     * A paid event for the wrong amount or the wrong currency is not
     * fulfilment; it is an incident.
     */
    public function matches(int $expectedAmount, string $expectedCurrency): bool
    {
        return $this->amountMinorUnits === $expectedAmount
            && strtoupper($this->currency) === strtoupper($expectedCurrency);
    }
}
