<?php

namespace App\Contracts\Payments;

final readonly class RefundResult
{
    public function __construct(
        public bool $succeeded,
        public string $reference,
        public int $amountMinorUnits,
        public string $currency,
        public ?string $failureReason = null,
    ) {}

    public static function failed(string $reason): self
    {
        return new self(false, '', 0, '', $reason);
    }
}
