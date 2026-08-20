<?php

namespace App\Services\Refunds;

/**
 * What the gateway said, reduced to what the service needs to decide.
 */
final readonly class AttemptOutcome
{
    private function __construct(
        public bool $succeeded,
        public string $reference = '',
        public ?string $failureReason = null,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(true, $reference);
    }

    public static function failed(string $reason): self
    {
        return new self(false, failureReason: $reason);
    }
}
