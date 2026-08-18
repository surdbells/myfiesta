<?php

namespace App\Contracts\Payments;

/**
 * Where the buyer goes afterwards, and how to keep a retry from charging twice.
 *
 * Note what is absent: an amount. The order carries that, computed from prices
 * held in the database. A caller cannot influence what is charged, which is the
 * whole point.
 */
final readonly class CheckoutOptions
{
    public function __construct(
        public string $successUrl,
        public string $cancelUrl,

        /**
         * Sent to the processor so a retried request cannot charge twice.
         *
         * Stored on the order, so a client that submits checkout again after a
         * timeout gets the same session rather than a second charge.
         */
        public string $idempotencyKey,

        /**
         * Passed through and returned on the webhook. Useful for tracing a
         * payment back to a request; never trusted as an instruction.
         */
        public array $metadata = [],
    ) {}
}
