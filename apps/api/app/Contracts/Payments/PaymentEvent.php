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

    /** The processor decided a dispute. Lost means the money is gone. */
    public const DISPUTE_WON = 'dispute_won';

    public const DISPUTE_LOST = 'dispute_lost';

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

        /**
         * What the processor calls the payment itself, when it says.
         *
         * An order is created against a checkout session; a dispute, and a
         * refund, are about the payment that session produced. They are
         * different identifiers, so the second one is kept the first time it
         * is seen — otherwise a dispute arrives naming a payment nothing here
         * has ever heard of.
         */
        public ?string $paymentReference = null,

        /**
         * For a refund notice, what the processor said about the refund.
         *
         * Refunds are the one event whose details the domain acts on beyond
         * the amount: which refund it was, whose it was, and whether it is a
         * running total. Each processor says those in its own shape, so the
         * gateway that parsed the payload fills this in.
         */
        public ?RefundNotice $refund = null,
    ) {}

    public function isDispute(): bool
    {
        return in_array($this->type, [self::DISPUTED, self::DISPUTE_WON, self::DISPUTE_LOST], true);
    }

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
