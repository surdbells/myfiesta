<?php

namespace App\Contracts\Payments;

/**
 * A processor telling us about a refund, in whichever form it tells it.
 *
 * Some of these are about a refund we asked for, and only confirm it. Others
 * are about one somebody made in the processor's own dashboard, which nothing
 * here would otherwise ever hear of. Telling the two apart is the whole job of
 * whatever reads this (ProcessorRefunds), so the notice carries every handle
 * the processor gave: its id for the refund, the id of ours it was tagged
 * with, and the amount.
 *
 * A notice is either about one refund (amountMinorUnits) or about everything
 * refunded on the payment so far (totalRefundedMinorUnits), which is what
 * Stripe's charge.refunded says. The running total is the safer of the two:
 * read twice, or out of order, it still adds up to the same thing.
 */
final readonly class RefundNotice
{
    /** The money is going back. A processor still settling it counts: it has said yes. */
    public const SUCCEEDED = 'succeeded';

    /** The processor is waiting on something, and has not said yes yet. */
    public const PENDING = 'pending';

    /** The processor tried and the money did not go back. */
    public const FAILED = 'failed';

    /**
     * @param  list<RefundNotice>  $parts  the single refunds a running total lists, when it lists them
     */
    public function __construct(
        public string $status,

        /** How much this one refund is for, in minor units; null for a running total. */
        public ?int $amountMinorUnits = null,

        /** Everything refunded on the payment so far, when that is what the notice says. */
        public ?int $totalRefundedMinorUnits = null,

        /** The processor's own id for the refund, when it gave one. */
        public ?string $processorReference = null,

        /** The id of our Refund row, when the refund was tagged with it on the way out. */
        public ?string $ourReference = null,

        public array $parts = [],
    ) {}

    public function isRunningTotal(): bool
    {
        return $this->totalRefundedMinorUnits !== null;
    }
}
