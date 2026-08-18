<?php

namespace App\Services\Checkout;

use App\Mail\TicketsIssued;
use App\Models\InventoryHold;
use App\Models\LedgerEntry;
use App\Models\Order;
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
            ]);

            $this->issuer->issueFor($locked);
            $this->writeLedger($locked);
            $this->releaseHolds($locked);

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
     * gross, what they discounted, what was collected for a tax authority, and
     * what the platform took. A single number cannot answer that, and the
     * question always comes eventually.
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

        LedgerEntry::create($common + [
            'type' => 'sale',
            'amount' => $order->subtotal_amount,
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

        if ($order->commission_amount > 0) {
            LedgerEntry::create($common + [
                'type' => 'commission',
                'amount' => -$order->commission_amount,
                'reason' => "Order {$order->reference}",
            ]);
        }
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
