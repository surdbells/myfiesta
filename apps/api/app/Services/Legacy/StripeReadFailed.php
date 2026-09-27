<?php

namespace App\Services\Legacy;

use RuntimeException;

/**
 * Stripe did not give an answer the reconciliation can use.
 *
 * Three different things, and they are handled differently: an id Stripe has
 * never heard of is a finding about that order; Stripe being slow or down is
 * a reason to try that order again later; and a key Stripe refuses is a reason
 * to stop, because every order after it would fail the same way and be
 * reported as missing when nothing is.
 */
final class StripeReadFailed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $missing = false,
        public readonly bool $stopsTheRun = false,
    ) {
        parent::__construct($message);
    }

    public static function missing(string $message): self
    {
        return new self($message, missing: true);
    }

    public static function unavailable(string $message): self
    {
        return new self($message);
    }

    public static function refused(string $message): self
    {
        return new self($message, stopsTheRun: true);
    }
}
