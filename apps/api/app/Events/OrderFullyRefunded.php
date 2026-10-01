<?php

namespace App\Events;

use App\Events\Concerns\SaidOnceCommitted;
use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

/**
 * An order that was a sale has had all of its money back.
 *
 * Said once, by RefundService, when the refund that completes it settles —
 * refunded here, by the organizer, by a cancelled night, or in the payment
 * processor's dashboard and found by reconciliation. A partial refund is not
 * this, and neither is a payment turned away that never became a sale: only
 * an order OrderPaid was said about can be fully refunded. Nor a refund
 * written down quietly at the cutover from the old platform, which is
 * history, not news.
 *
 * Heard only once the transaction has committed, like OrderPaid.
 */
final class OrderFullyRefunded implements ShouldDispatchAfterCommit
{
    use SaidOnceCommitted, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
