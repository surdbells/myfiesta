<?php

namespace App\Contracts\Payments;

use App\Models\Order;
use DateTimeInterface;

/**
 * A processor that can be asked whether a refund already happened.
 *
 * The question a timeout leaves behind. Before a refund that got no answer is
 * sent again, the processor is asked whether it has one already — so the
 * second request can only ever be the first one arriving, never a second
 * payment. Separate from PaymentGateway because only the follow-up needs it
 * (RefundService::followUp), and a gateway that cannot answer simply leaves the
 * refund waiting for a person.
 */
interface FindsRefunds
{
    /**
     * The refund made for one of our Refund rows, if the processor has it.
     *
     * Returns a succeeded result when the money has gone or is going back, a
     * failed one when the processor tried and it did not, and null when the
     * processor has no such refund. Throws when the processor cannot be asked,
     * which is not the same as it saying no.
     *
     * @param  string  $idempotencyKey  what the refund was sent with, and tagged with where the processor allows
     * @param  list<string>  $alreadyKnown  the processor's ids for refunds already on record for this order
     */
    public function findRefund(
        Order $order,
        string $idempotencyKey,
        int $amountMinorUnits,
        DateTimeInterface $since,
        array $alreadyKnown = [],
    ): ?RefundResult;
}
