<?php

namespace App\Events;

use App\Events\Concerns\SaidOnceCommitted;
use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

/**
 * An order was paid and its tickets exist.
 *
 * Said once for each order, by Fulfiller, at the moment it issues the
 * tickets: an order paid on time, one whose money arrived after its checkout
 * was closed, a free one and a sale at the door alike. Not for a payment
 * turned away — no tickets, and the money goes back. A webhook delivered
 * twice finds the order already paid and says nothing the second time.
 *
 * Heard only once the transaction that paid it has committed, so a listener
 * never acts on an order a rollback then took back. Listeners live in
 * app/Listeners and are found there, and are queued (SaidOnceCommitted says
 * why); a friend discount's link and its reward start here.
 */
final class OrderPaid implements ShouldDispatchAfterCommit
{
    use SaidOnceCommitted, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
