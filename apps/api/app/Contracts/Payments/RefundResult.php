<?php

namespace App\Contracts\Payments;

/**
 * What a processor said about a refund we asked for.
 *
 * Three answers, not two. Yes, no, and "we don't know": a request that timed
 * out, or that the processor answered with its own error, may have paid the
 * money back before anybody heard. Reading that as a no is how a refund is
 * tried again and the buyer is paid twice, so it is kept apart (unknown()) and
 * asked about again later rather than decided on the spot.
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $succeeded,
        public string $reference,
        public int $amountMinorUnits,
        public string $currency,
        public ?string $failureReason = null,
        /** Whether nobody can yet say if the money went back. */
        public bool $unknown = false,
    ) {}

    /** The processor said no, and nothing moved. Safe to ask again. */
    public static function failed(string $reason): self
    {
        return new self(false, '', 0, '', $reason);
    }

    /** No answer we can trust. The money may or may not have gone back. */
    public static function unknown(string $reason): self
    {
        return new self(false, '', 0, '', $reason, unknown: true);
    }
}
