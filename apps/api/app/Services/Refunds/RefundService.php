<?php

namespace App\Services\Refunds;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Allocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Returning money, and turning off the tickets it bought.
 *
 * Refunds are by ticket, never by amount. An organizer refunding "£40" leaves
 * every ticket valid and the door with no idea, so the person who was paid back
 * still walks in — and the shortfall only surfaces at reconciliation, weeks
 * later, as a number nobody can attribute. Naming the tickets makes the
 * proportion exact and voiding them part of the same act.
 *
 * The gateway call happens outside the database transaction, deliberately.
 * Holding a row lock open across an HTTP request to Stripe is how a payment
 * processor's slow afternoon becomes a database incident. Instead the refund is
 * written as pending under the lock — which reserves the amount against the
 * cap, so a second request cannot refund the same tickets — then attempted,
 * then settled under the lock again.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<string>|null  $ticketIds  null refunds everything still refundable
     *
     * @throws RefundRefused when the request cannot be honoured
     */
    public function refund(
        Order $order,
        ?array $ticketIds = null,
        ?User $issuer = null,
        ?string $reason = null,
    ): Refund {
        $refund = $this->reserve($order, $ticketIds, $issuer, $reason);

        $result = $this->attempt($order, $refund);

        $settled = $this->settle($order, $refund, $result);

        // Money leaving the platform is the single most important thing to be
        // able to attribute afterwards. Recorded whether or not the provider
        // accepted it — a refused refund is exactly what somebody investigates.
        $this->auditor->record(
            $settled->status === 'succeeded' ? 'refund.processed' : 'refund.failed',
            $order,
            $issuer,
            metadata: [
                'refund_id' => $settled->id,
                'amount' => $settled->amount,
                'currency' => $order->currency,
                'tickets' => $ticketIds === null ? 'all remaining' : count($ticketIds),
                'reason' => $reason,
                'failure' => $settled->failure_reason,
            ],
        );

        return $settled;
    }

    /**
     * Write the refund as pending, under the order's lock.
     *
     * Everything that decides whether a refund is allowed happens here, in one
     * place, while nothing else can be deciding the same thing.
     */
    private function reserve(
        Order $order,
        ?array $ticketIds,
        ?User $issuer,
        ?string $reason,
    ): Refund {
        return DB::transaction(function () use ($order, $ticketIds, $issuer, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['paid', 'partially_refunded'], true)) {
                throw RefundRefused::because(
                    'Only a paid order can be refunded. This one is '.$locked->status.'.'
                );
            }

            if ($locked->total_amount === 0) {
                // Nothing was ever charged — a comp, an RSVP, or an order fully
                // covered by a code. There is no money to send back, and saying
                // so is better than a gateway call for zero.
                throw RefundRefused::because(
                    'This order was free, so there is nothing to return.'
                );
            }

            $tickets = $this->refundableTickets($locked, $ticketIds);

            if ($tickets->isEmpty()) {
                throw RefundRefused::because(
                    $ticketIds === null
                        ? 'Every ticket on this order has already been refunded.'
                        : 'Those tickets are not on this order, or have already been refunded.'
                );
            }

            $share = $this->shareFor($locked, $tickets);

            if ($share['amount'] <= 0) {
                throw RefundRefused::because(
                    'Those tickets were free, so there is nothing to return.'
                );
            }

            // Pending refunds count against the cap as well as succeeded ones.
            // A refund in flight is money the buyer is about to have.
            $alreadyOut = (int) Refund::query()
                ->where('order_id', $locked->id)
                ->whereIn('status', ['pending', 'succeeded'])
                ->sum('amount');

            if ($alreadyOut + $share['amount'] > $locked->total_amount) {
                throw RefundRefused::because(
                    'That is more than is left on this order to refund.'
                );
            }

            $refund = Refund::create([
                'order_id' => $locked->id,
                'event_id' => $locked->event_id,
                'organization_id' => $locked->organization_id,
                'issued_by' => $issuer?->id,
                'currency' => $locked->currency,
                'amount' => $share['amount'],
                'tax_amount' => $share['tax'],
                'service_charge_amount' => $share['service_charge'],
                'gateway' => $locked->gateway,
                'status' => 'pending',
                'reason' => $reason,
            ]);

            $refund->tickets()->attach($tickets->pluck('id')->all());

            return $refund;
        });
    }

    /**
     * Ask the gateway for the money back.
     *
     * A thrown exception is treated as a failure rather than allowed to escape.
     * If it escaped, the pending refund would stay pending forever, holding
     * part of the order's balance hostage against a retry that would then be
     * refused as over-refunding.
     */
    private function attempt(Order $order, Refund $refund): AttemptOutcome
    {
        $gateway = $this->gatewayFor($order);

        if (! $gateway) {
            return AttemptOutcome::failed(
                'No payment processor is configured for '.($order->gateway ?? 'this order').'.'
            );
        }

        try {
            $result = $gateway->refund($order, $refund->amount, $refund->reason);
        } catch (Throwable $e) {
            // The detail goes to the log; the organizer gets a sentence. A
            // processor's exception text is not something to put in a console.
            Log::error('Refund threw', [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'gateway' => $order->gateway,
                'exception' => $e->getMessage(),
            ]);

            return AttemptOutcome::failed('The payment processor could not be reached.');
        }

        if (! $result->succeeded) {
            Log::warning('Refund refused by gateway', [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'reason' => $result->failureReason,
            ]);

            return AttemptOutcome::failed(
                $result->failureReason ?: 'The payment processor refused the refund.'
            );
        }

        return AttemptOutcome::succeeded($result->reference);
    }

    /**
     * Record what happened, and act on it.
     *
     * Tickets are voided and the ledger written only on success. A failed
     * refund must leave the tickets valid — the buyer still holds something
     * they paid for.
     */
    private function settle(Order $order, Refund $refund, AttemptOutcome $outcome): Refund
    {
        return DB::transaction(function () use ($order, $refund, $outcome) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $outcome->succeeded) {
                $refund->update([
                    'status' => 'failed',
                    'failure_reason' => $outcome->failureReason,
                ]);

                return $refund->refresh();
            }

            $refund->update([
                'status' => 'succeeded',
                'gateway_reference' => $outcome->reference,
            ]);

            Ticket::whereIn('id', $refund->tickets()->pluck('tickets.id'))
                ->update(['status' => 'refunded']);

            $this->writeLedger($locked, $refund);

            $refunded = (int) Refund::query()
                ->where('order_id', $locked->id)
                ->where('status', 'succeeded')
                ->sum('amount');

            $locked->update([
                'status' => $refunded >= $locked->total_amount ? 'refunded' : 'partially_refunded',
                // Set on the first refund and left alone. This is when the
                // order started being refunded, which is the date support is
                // asked about; each refund carries its own timestamp.
                'refunded_at' => $locked->refunded_at ?? now(),
            ]);

            return $refund->refresh();
        });
    }

    /**
     * Which tickets this refund is for.
     *
     * A checked-in ticket is refundable. Somebody who came in and was refunded
     * anyway is a decision an organizer is allowed to make — goodwill, a
     * cancelled headliner, a complaint — and refusing it here would only send
     * them to the database.
     */
    private function refundableTickets(Order $order, ?array $ticketIds): Collection
    {
        $query = $order->tickets()
            ->whereNotIn('status', ['refunded', 'void']);

        if ($ticketIds !== null) {
            $query->whereIn('id', $ticketIds);
        }

        return $query->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * What those tickets are worth, out of what was actually charged.
     *
     * Not the sum of their prices. An order carries a discount and a tax, and a
     * ticket's share of the money is its share of the whole — so the split is
     * done across every ticket on the order at once and this refund takes the
     * parts belonging to its own. Splitting only the refunded tickets would
     * lose the rounding to whoever is refunded last.
     *
     * @return array{amount: int, tax: int, service_charge: int}
     */
    private function shareFor(Order $order, Collection $tickets): array
    {
        // Ordered by id as well as time, and it matters. Tickets on one order
        // are written in a single transaction and share a created_at to the
        // microsecond, so time alone leaves the sequence up to the planner.
        // The allocation hands its leftover units out by position — an order
        // that shuffles between two partial refunds would give the same spare
        // penny to both, or to neither.
        $all = $order->tickets()->orderBy('created_at')->orderBy('id')->get();

        // A ticket's weight is what its type cost, snapshotted on the order
        // line. Comps issued against the same order weigh nothing and so
        // refund nothing, which is correct.
        $prices = $order->lines->keyBy('ticket_type_id');
        $weights = $all
            ->map(fn (Ticket $t) => (int) ($prices[$t->ticket_type_id]->unit_price_amount ?? 0))
            ->all();

        $wanted = $tickets->pluck('id')->all();
        $positions = $all
            ->keys()
            ->filter(fn (int $i) => in_array($all[$i]->id, $wanted, true))
            ->all();

        $take = function (int $total) use ($weights, $positions): int {
            $parts = Allocation::split($total, $weights);

            return array_sum(array_map(fn (int $i) => $parts[$i], $positions));
        };

        return [
            'amount' => $take($order->total_amount),
            'tax' => $take($order->tax_amount),
            'service_charge' => $take($order->service_charge_amount),
        ];
    }

    /**
     * The reversal, written the way the sale was written.
     *
     * Two entries, because two things leave the organizer's balance. The refund
     * entry is their own money going back. The tax entry returns what was being
     * held for a tax authority on a sale that has now partly unhappened.
     *
     * The service charge is in neither, and that is the point. The buyer gets
     * it back — it is part of `$refund->amount`, which is what the gateway
     * sends — but it was the platform's revenue, never the organizer's, so it
     * cannot leave a balance it never entered. Subtracting it here would charge
     * the organizer for refunding a fee somebody else collected.
     *
     * Written this way, a fully refunded order nets the organizer to exactly
     * zero. The platform is out the gateway fee on both legs, which no
     * processor returns and which is therefore a real cost of a refund rather
     * than an accounting entry.
     */
    private function writeLedger(Order $order, Refund $refund): void
    {
        $common = [
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'currency' => $order->currency,
            'occurred_at' => now(),
        ];

        $note = "Refund for order {$order->reference}";

        // What the organizer actually gives back: the gross that was returned,
        // less the tax that was never theirs and less the service charge that
        // was never theirs either.
        LedgerEntry::create($common + [
            'type' => 'refund',
            'amount' => -($refund->amount - $refund->tax_amount - $refund->service_charge_amount),
            'reason' => $note,
        ]);

        if ($refund->tax_amount > 0) {
            LedgerEntry::create($common + [
                'type' => 'tax',
                'amount' => $refund->tax_amount,
                'reason' => $note,
            ]);
        }

    }

    /**
     * The same processor that took the money, never a re-route by currency.
     *
     * A refund goes back down the path the payment came up. Choosing by
     * currency would send a Stripe charge's refund to Paystack the day Paystack
     * starts supporting CAD.
     */
    private function gatewayFor(Order $order): ?PaymentGateway
    {
        try {
            return $this->gateways->named((string) $order->gateway);
        } catch (Throwable) {
            return null;
        }
    }
}
