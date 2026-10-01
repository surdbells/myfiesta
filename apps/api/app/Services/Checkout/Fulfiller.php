<?php

namespace App\Services\Checkout;

use App\Events\OrderPaid;
use App\Mail\TicketsIssued;
use App\Models\AddOn;
use App\Models\Code;
use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Audit\Auditor;
use App\Services\Disputes\ActivityLog;
use App\Services\Integrations\Payloads;
use App\Services\Integrations\Webhooks;
use App\Services\Payments\GatewayFee;
use App\Services\Refunds\RefundRefused;
use App\Services\Refunds\RefundService;
use App\Services\Resale\Resale;
use App\Services\Sms\Texts;
use App\Services\Waitlist\Waitlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Marks an order paid and issues its tickets.
 *
 * Reached from exactly three places: a verified webhook, a zero-total order
 * that never needed a gateway, and a sale at the door, where the money is
 * already in the tin. Nothing a browser sends can call it. The
 * previous platform inverted this — the client posted a session id along with
 * its own base64 cart, and the server minted whatever the cart said, so a
 * genuine one-dollar payment could be redeemed for any tickets at all.
 *
 * Tickets come from the order's own lines, written server-side when the order
 * was created and untouched since.
 */
class Fulfiller
{
    /**
     * The states a payment can still turn into tickets.
     *
     * Pending is the ordinary case. Cancelled is a checkout closed as
     * abandoned, or a payment page that expired, whose money arrived anyway —
     * a Paystack transfer confirming hours later. Everything else has been
     * decided already: a paid or part-refunded order has its tickets, and a
     * refunded one has had its money back, so a payment notice for either is
     * a repeat. A failed one is left alone as well, and said out loud (below).
     */
    private const FULFILLABLE = ['pending', 'cancelled'];

    public function __construct(
        private readonly TicketIssuer $issuer,
        private readonly Stock $stock,
    ) {}

    /**
     * Idempotent by design.
     *
     * Gateways retry webhooks, sometimes for days, and a duplicate delivery
     * must not mint a second set of tickets. The status check inside the
     * transaction is the guard; calling this twice is safe.
     */
    public function fulfil(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! in_array($locked->status, self::FULFILLABLE, true)) {
                // Only reached with money in hand, and a processor that said
                // "failed" and then "paid" about one order is something to
                // look at rather than guess about. Nothing is issued and
                // nothing is kept quiet.
                if ($locked->status === 'failed') {
                    Log::alert('A payment arrived for an order already marked failed. Nothing was issued.', [
                        'order' => $locked->reference,
                        'gateway' => $locked->gateway,
                    ]);
                }

                return $locked;
            }

            // Turned away already, and its money is on the way back. A second
            // delivery of the same payment must not be the one that decides
            // differently.
            if ($locked->refunds()->whereIn('status', ['pending', 'succeeded'])->exists()) {
                return $locked;
            }

            $nightIsOff = $this->nightIsOff($locked);

            if ($nightIsOff !== null) {
                return $this->turnAway($locked, $nightIsOff);
            }

            if (! $this->hasRoom($locked)) {
                return $this->turnAway($locked, TurnedAway::SoldOut);
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

            $tickets = $this->issuer->issueFor($locked);

            // Into the ticket history, in the same transaction as the
            // tickets, so it says they were issued exactly when they were.
            app(ActivityLog::class)->issued($locked, $tickets);

            $this->writeLedger($locked);
            $this->releaseHolds($locked);

            // Somebody from the waitlist who got in. Only when we know who
            // they are: a door sale may carry no address.
            if (filled($locked->buyer_email)) {
                app(Waitlist::class)->markPurchased($locked->event_id, $locked->buyer_email);
            }

            Code::recount($locked->code_id);
            Code::recount($locked->access_code_id);

            // Whoever asked to hear about sales. Inside the transaction so a
            // rolled-back order never announces itself; the delivery goes
            // after commit, and a receiver that is down never touches this.
            app(Webhooks::class)->emit($locked->organization_id, 'order.paid', app(Payloads::class)->order($locked->fresh()));

            // And the platform's own features: heard once the payment has
            // committed, and only by this call — a repeated notice returned
            // above, before anything was issued. A feature that fails there
            // is reported and stops there; the email, the payout and the text
            // below still go (SaidOnceCommitted).
            OrderPaid::dispatch($locked);

            // Queued, and dispatched only after the transaction commits.
            // Sending inside it risks a buyer holding tickets in their inbox
            // that a rollback then erased.
            //
            // Nowhere to send it when nobody gave an address: a walk-up who
            // paid cash is scanned in on the spot and holds nothing.
            if (filled($locked->buyer_email)) {
                DB::afterCommit(fn () => Mail::to($locked->buyer_email)
                    ->send(new TicketsIssued($locked)));
            }

            /*
             * Somebody gave a place back and this order took it.
             *
             * After the commit, with the mail, and for a sharper reason than
             * tidiness: paying the seller back calls the payment processor
             * over the internet, and doing that inside this transaction would
             * hold the buyer's order locked for as long as Stripe takes to
             * answer. A processor having a slow morning would become a
             * checkout having one.
             *
             * A failure here is loud and changes nothing about this order:
             * the buyer has paid and holds tickets either way.
             */
            DB::afterCommit(fn () => app(Resale::class)->matchPaidOrder($locked));

            // And by text, where a text is the thing that gets read. After the
            // commit for the same reason as the email, and never instead of
            // it: the email is the one that holds the tickets.
            if (filled($locked->buyer_phone)) {
                DB::afterCommit(fn () => app(Texts::class)->ticketsReady($locked->fresh()->load('event')));
            }

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

        /*
         * Money the organizer already has.
         *
         * A door sale was paid into their own tin, or onto their own terminal,
         * or straight into their bank. We never touched it, so we cannot pay
         * it out — and an organizer whose balance grew by cash they are
         * holding would be settled twice for one ticket.
         *
         * Written rather than omitted, then taken back out: the sale, its tax
         * and its discount are recorded exactly as for any order, so the
         * night's gross still reads as the night's gross, and this entry
         * removes the organizer's share from what we owe. The two cancel, and
         * the reason says which door it went through.
         */
        if ($order->channel === 'door') {
            LedgerEntry::create($common + [
                'type' => 'collected',
                'amount' => -$order->net_revenue_amount,
                'reason' => 'Taken at the door — '.($order->payment_method ?? 'unknown'),
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
     *
     * This order's own holds, and only those. It used to be the oldest live
     * holds on the same ticket types, as many rows as the order had places —
     * whoever they belonged to, and counted in rows although each row carries
     * a quantity. Paying for one order released other shoppers' reservations
     * while they were still on the payment page, and the places it freed
     * could then be sold twice.
     *
     * Both kinds: a held table is stock somebody else could not buy, and it
     * has been bought now.
     */
    private function releaseHolds(Order $order): void
    {
        InventoryHold::query()->where('order_id', $order->id)->delete();
    }

    /**
     * Whether the night this order is for is still happening.
     *
     * Asked before there is any question of room, and of every payment, late
     * or not. Money can arrive for a night that has been called off, is over,
     * was taken down by staff, or whose organizer the platform has suspended —
     * a Paystack transfer confirming after the organizer cancelled, a buyer
     * still on the payment page when they did — and there is usually room for
     * it, because nobody else could buy either. Honoured on room alone, it
     * became tickets for a night that is not happening, and no refund:
     * cancelling refunds the orders that were paid when it ran
     * (EventCanceller), and this one was still pending then.
     *
     * "Over" here is once the door has closed, not the listed end. Checkout
     * stops selling at the listed end, so what arrives between the two is a
     * payment that was already on its way, for a ticket that still gets
     * somebody in (TurnedAway::doorCloses).
     *
     * Not for a sale at the door. The cash is already in the tin, checkout
     * refused the sale if the event was off (CheckoutService), and a door can
     * stay open past the listed end. Nor for a free order, which is fulfilled
     * in the same request that checked the event was on sale and not over,
     * and has nothing to send back.
     */
    private function nightIsOff(Order $order): ?TurnedAway
    {
        if ($order->soldAtDoor() || ! $order->requiresPayment()) {
            return null;
        }

        // Read whole, deleted or not: an event gone from every list is still
        // the one this money was for, and a relation that hides it would read
        // as no event at all.
        return TurnedAway::forEvent(Event::withTrashed()->find($order->event_id), now());
    }

    /**
     * Whether there is room for everything on the order.
     *
     * Ordinarily there is nothing to check. The order still holds, live,
     * every place it is for: the stock was counted when the hold was taken,
     * and nobody else could have it since.
     *
     * But a payment can outlive its hold. Paystack's page never closes, a
     * bank transfer can confirm an hour later, and a webhook can be retried
     * for days; by then the places may have been sold to somebody who paid on
     * time. Honouring it anyway is how an event oversells — a thousand
     * tickets for a room of nine hundred, and the door finds out.
     *
     * So each place no longer held is counted again, the way checkout counts
     * it and under the same row locks (Stock): issued tickets and other
     * orders' live holds. If it all fits, the late payment is honoured as if
     * it were on time. If any of it does not, none of it is issued — half an
     * order is not what anybody paid for.
     *
     * A tier or add-on the organizer has removed since has no room at all.
     * It is off sale, and a place taken off sale has gone as surely as one
     * sold. It was looked up in a way that threw instead: the notice failed,
     * every retry failed the same way, and the buyer's money sat there with
     * nothing issued and nothing sent back. One still held is another
     * matter — the hold was a promise made while it was on sale, and is kept.
     */
    private function hasRoom(Order $order): bool
    {
        $held = $order->holds()->live()->get(['ticket_type_id', 'add_on_id', 'quantity']);

        foreach ($this->wanted($order) as [$column, $id, $quantity]) {
            if ((int) $held->where($column, $id)->sum('quantity') >= $quantity) {
                continue;
            }

            $item = $column === 'ticket_type_id'
                ? TicketType::query()->find($id)
                : AddOn::query()->find($id);

            if ($item === null) {
                return false;
            }

            $left = $item instanceof TicketType
                ? $this->stock->ticketsLeft($item, besides: $order)
                : $this->stock->addOnsLeft($item, besides: $order);

            if ($left !== null && $left < $quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the order is for, one entry per thing sold.
     *
     * Tickets before add-ons, as checkout takes them, and each kind in a fixed
     * order after that — so two late payments for the same night lock the
     * same rows the same way round, rather than each waiting on the other.
     *
     * @return list<array{0: 'ticket_type_id'|'add_on_id', 1: string, 2: int}>
     */
    private function wanted(Order $order): array
    {
        $wanted = [];

        foreach ($order->lines()->get() as $line) {
            $column = $line->ticket_type_id !== null ? 'ticket_type_id' : 'add_on_id';
            $key = ($column === 'ticket_type_id' ? '0:' : '1:').$line->{$column};

            $wanted[$key] ??= [$column, (string) $line->{$column}, 0];
            $wanted[$key][2] += (int) $line->quantity;
        }

        ksort($wanted, SORT_STRING);

        return array_values($wanted);
    }

    /**
     * A payment for places that have gone, or for a night that is off.
     *
     * Nothing is issued, so nothing is oversold. The money arrived all the
     * same, and it goes back — all of it, automatically, because the buyer
     * did nothing wrong and there is no organizer to ask: nothing was sold.
     *
     * The order keeps a record that the money came: when it came, and what
     * the processor kept for taking it, which no processor gives back on a
     * refund and is the platform's cost, not the organizer's. No ledger
     * entry is written, now or when the refund settles; the organizer never
     * made this sale, and their balance never sees it.
     *
     * The refund itself goes after the commit. It calls the processor over
     * the internet, and doing that inside this transaction would hold the
     * order — and the ticket types just counted — locked for as long as the
     * processor takes to answer. The buyer is told once the processor has
     * accepted it, so the email is true when it is read — and says why, from
     * the reason the refund carries (TurnedAway): places that had gone, or a
     * night that is not happening.
     */
    private function turnAway(Order $order, TurnedAway $why): Order
    {
        // Whatever of it was still held goes back on sale now.
        $this->releaseHolds($order);

        app(Auditor::class)->record($why->auditAction(), $order, metadata: [
            'reference' => $order->reference,
            'total' => $order->total_amount,
            'currency' => $order->currency,
            'why' => $why->value,
        ]);

        // A free order has nothing to give back. It is closed, and that is
        // all.
        if (! $order->requiresPayment()) {
            $order->update(['status' => 'cancelled']);

            return $order->refresh();
        }

        $order->update([
            'paid_at' => now(),
            'gateway_fee_amount' => $order->gateway === null
                ? 0
                : GatewayFee::on($order->total, $order->gateway)->amount,
        ]);

        DB::afterCommit(fn () => $this->giveItBack($order, $why));

        return $order->refresh();
    }

    /**
     * Send the money back. The buyer is told once it has gone.
     *
     * The telling is RefundService's, because "once it has gone" is not always
     * now: a refund that got no answer is confirmed later, by the processor's
     * notice or a follow-up, and the email goes then. A refund the processor
     * refuses stays on the order as a failed refund, with an alert, for a
     * person to finish (RefundService::refundUnfulfilled). One refused here as
     * already under way is a second delivery arriving behind the first, and
     * the first is dealing with it.
     */
    private function giveItBack(Order $order, TurnedAway $why): void
    {
        try {
            app(RefundService::class)->refundUnfulfilled($order, $why->refundReason());
        } catch (RefundRefused $e) {
            Log::warning('A payment that became no tickets was not refunded here.', [
                'order' => $order->reference,
                'why' => $why->value,
                'reason' => $e->getMessage(),
            ]);
        }
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
