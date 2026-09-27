<?php

namespace App\Contracts\Payments;

use App\Models\Order;

/**
 * A processor that can say, when asked, how much has gone back on a payment.
 *
 * Stripe announces refunds made in its dashboard as a running total
 * (charge.refunded), and the total is read against what is on record here. A
 * refund of ours still waiting for an answer is on record, so a dashboard
 * refund made while ours waits is hidden behind it — and if ours then comes to
 * nothing, the announcement is not made a second time. Asking for the total is
 * how that money is found after all (RefundService::afterRefusal).
 *
 * Separate from PaymentGateway and FindsRefunds because only a processor that
 * counts refunds this way needs asking. Paystack announces each refund on its
 * own, and one hidden that way is still announced.
 */
interface TotalsRefunds
{
    /**
     * Everything refunded on the order's payment so far, as a running total.
     *
     * The notice lists the refunds it is made of, so the ones that are ours
     * can be told from the ones that are not. Throws when the processor cannot
     * be asked, which is not the same as it having refunded nothing.
     */
    public function refundedSoFar(Order $order): RefundNotice;
}
