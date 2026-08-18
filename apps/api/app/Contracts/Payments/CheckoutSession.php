<?php

namespace App\Contracts\Payments;

/**
 * A started payment: where to send the buyer, and how to recognise it later.
 */
final readonly class CheckoutSession
{
    public function __construct(
        /** The processor's identifier, stored on orders.gateway_reference. */
        public string $reference,

        /** Where the buyer completes payment. */
        public string $redirectUrl,

        /**
         * When the processor stops honouring this session.
         *
         * Inventory holds should outlive it by a margin — expiring a hold while
         * the buyer is still on the payment page sells their tickets to someone
         * else mid-transaction.
         */
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}
}
