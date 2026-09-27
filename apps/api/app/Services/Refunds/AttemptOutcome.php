<?php

namespace App\Services\Refunds;

/**
 * What the gateway said, reduced to what the service needs to decide.
 *
 * Yes, no, or nobody knows. The third is a request that timed out or met the
 * processor's own error: the money may have gone back, so the refund is left
 * waiting to be asked about again rather than called failed — failed tells an
 * organizer it is safe to send it again.
 */
final readonly class AttemptOutcome
{
    private function __construct(
        public bool $succeeded,
        public string $reference = '',
        public ?string $failureReason = null,
        public bool $unknown = false,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(true, $reference);
    }

    public static function failed(string $reason): self
    {
        return new self(false, failureReason: $reason);
    }

    public static function unknown(string $reason): self
    {
        return new self(false, failureReason: $reason, unknown: true);
    }
}
