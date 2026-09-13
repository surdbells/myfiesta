<?php

namespace App\Services\Checkout;

use App\Mail\TicketsIssued;
use App\Models\Code;
use App\Services\Waitlist\Waitlist;
use App\Models\InventoryHold;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Payments\GatewayFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Marks an order paid and issues its tickets.
 *
 * Reached from exactly two places: a verified webhook, and a zero-total order
 * that never needed a gateway. Nothing a browser sends can call it. The
 * previous platform inverted this — the client posted a session id along with
 * its own base64 cart, and the server minted whatever the cart said, so a
 * genuine one-dollar payment could be redeemed for any tickets at all.
 *
 * Tickets come from the order's own lines, written server-side when the order
 * was created and untouched since.
 */
class Fulfiller
{
    public function __construct(private readonly TicketIssuer $issuer) {}

    /**
     * Idempotent by design.
     *
     * Gateways retry webhooks, sometimes for days, and a duplicate delivery
     * must not mint a second set of tickets. The paid check inside the
     * transaction is the guard; calling this twice is safe.
     */
    public function fulfil(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status === 'paid') {
                return $locked;
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                // Recorded now, while the gateway that took it is known. An
                // order that settles leaves a knowable cost behind; one that
                // never settles keeps its null, because zero would read as
                // free rather than as never charged.
                'gateway_fee_amount' => $locked->gateway === null
                    ? 0
                    : GatewayFee::on($locked->total, $locked->gateway)->amount,
            ]);

            $this->issuer->issueFor($locked);
            $this->writeLedger($locked);
            $this->releaseHolds($locked);

            // A paid use of its code, now that it is one.
            // Somebody from the waitlist who got in.
            app(Waitlist::class)->markPurchased($locked->event_id, $locked->buyer_email);

            Code::recount($locked->code_id);
            Code::recount($locked->access_code_id);

            // Queued, and dispatched only after the transaction commits.
            // Sending inside it risks a buyer holding tickets in their inbox
            // that a rollback then erased.
            DB::afterCommit(fn () => Mail::to($locked->buyer_email)
                ->send(new TicketsIssued($locked)));

            return $locked->refresh();
        });
    }

    /**
     * Record the money as separable facts rather than one net figure.
     *
     * An organizer asking why they are owed what they are owed needs to see
     * gross, what they discounted, and what was collected for a tax authority.
     * A single number cannot answer that, and the question always comes
     * eventually.
     *
     * The invariant these entries exist to satisfy: they sum to the order's
     * net_revenue_amount, in both tax modes. That is what the organizer is
     * owed, and it is asserted in CheckoutFlowTest rather than left as an
     * intention — the two ways of arriving at the same figure disagreed
     * silently for as long as nothing compared them.
     */
    private function writeLedger(Order $order): void
    {
        $common = [
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'currency' => $order->currency,
            'occurred_at' => now(),
        ];

        // The gross ticket side, tax included — not the subtotal.
        //
        // Where tax is added on top, the subtotal does not contain it, and the
        // tax entry below subtracts it regardless. Recording the subtotal here
        // therefore removed a tax that was never added: a Canadian organizer
        // selling 20000 with 2600 of HST came out at 17400 rather than 20000,
        // short by the whole tax, on every sale. Settlements pay from this
        // ledger.
        //
        // Where tax is inside the price the subtotal already contains it, so
        // the two cases converge on the same rule: the sale is what the ticket
        // side of the charge was, and the entries below take out the parts
        // that were never the organizer's.
        $grossTicketSide = $order->tax_inclusive
            ? $order->subtotal_amount
            : $order->subtotal_amount + $order->tax_amount;

        LedgerEntry::create($common + [
            'type' => 'sale',
            'amount' => $grossTicketSide,
            'reason' => "Order {$order->reference}",
        ]);

        if ($order->discount_amount > 0) {
            // Negative: the organizer chose to forgo this, so it reduces what
            // they are owed rather than what the buyer paid.
            LedgerEntry::create($common + [
                'type' => 'discount',
                'amount' => -$order->discount_amount,
                'reason' => "Order {$order->reference}",
            ]);
        }

        if ($order->tax_amount > 0) {
            // Never the organizer's money. Held on behalf of a tax authority
            // whichever side of the price it sat on.
            LedgerEntry::create($common + [
                'type' => 'tax',
                'amount' => -$order->tax_amount,
                'reason' => "Order {$order->reference}",
            ]);
        }

        // No entry for the service charge, deliberately.
        //
        // The organizer's ledger records what the organizer is owed, and the
        // service charge was never their money — the buyer paid it to the
        // platform on top of the ticket price. Writing it here as a negative
        // is what made an organizer selling a 5000 ticket appear to be owed
        // 4500, and it would have been settled at that.
        //
        // The platform's side of the same transaction lives on the order, as
        // service_charge_amount against gateway_fee_amount.
    }

    /**
     * The hold has done its job once tickets exist.
     *
     * Leaving it would double-count against availability, since issued tickets
     * are now themselves part of the count.
     */
    private function releaseHolds(Order $order): void
    {
        $ticketTypeIds = $order->lines()->pluck('ticket_type_id');

        InventoryHold::whereIn('ticket_type_id', $ticketTypeIds)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->limit($order->lines()->sum('quantity'))
            ->delete();
    }

    /**
     * An order with nothing to charge.
     *
     * Comps, full-value codes, RSVPs, free events. No gateway is involved, so
     * there is no webhook to wait for and fulfilment happens immediately.
     */
    public function fulfilFree(Order $order): Order
    {
        if ($order->total_amount > 0) {
            throw new \LogicException(
                'fulfilFree() called on an order with a balance. Only a verified '
                .'webhook may mark a payable order paid.'
            );
        }

        return $this->fulfil($order);
    }
}
