<?php

namespace App\Services\Legacy;

use App\Models\Order;
use App\Models\Refund;
use App\Services\Audit\Auditor;
use App\Services\Refunds\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writing down refunds Stripe has already made.
 *
 * The old platform's database never heard about money that went back through
 * Stripe, so an imported order can say "paid" about a refund made months ago.
 * Left that way, the organizer's balance includes it and the next payout pays
 * it out a second time.
 *
 * The writing itself is RefundService::recordMadeElsewhere, the same path a
 * Stripe notice about a dashboard refund takes after the cutover: one Refund
 * row marked as made at the processor, the same ledger reversal, the same
 * order status arithmetic, and the tickets stopped when the whole order has
 * gone back. Two ways of recording one kind of refund would drift apart, and
 * the one the webhooks use is the one that is kept up to date.
 *
 * What this adds is the part a reconciliation needs and a notice does not.
 * It is keyed on Stripe's refund id, across every order, so running it again
 * records nothing new and one refund is never written against two orders; a
 * refund issued through this platform after the cutover carries the same id
 * and is recognised too. It only acts on an order that is paid or partly
 * refunded already, so there is no way through it to mark anything paid. And
 * it refuses rather than trims a refund bigger than what is left: trimming
 * would write down a figure Stripe never paid out.
 */
class StripeRefundRecorder
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{id: string, amount: int, currency: string, status: string, created: int}>  $stripeRefunds
     * @return list<string> one line per Stripe refund, saying what became of it
     */
    public function record(Order $order, array $stripeRefunds): array
    {
        $succeeded = array_values(array_filter(
            $stripeRefunds,
            fn (array $r) => $r['status'] === 'succeeded',
        ));

        // Oldest first, so a partial refund followed by the rest records as
        // partly refunded and then refunded — which is what happened.
        usort($succeeded, fn (array $a, array $b) => $a['created'] <=> $b['created']);

        $said = [];

        foreach ($succeeded as $stripeRefund) {
            [$refund, $why] = $this->recordOne($order, $stripeRefund);

            if ($refund === null) {
                if ($why !== null) {
                    $said[] = "left {$stripeRefund['id']}: {$why}";
                }

                continue;
            }

            $when = CarbonImmutable::createFromTimestamp($stripeRefund['created']);

            // Beside the entry RefundService writes. That one says a refund
            // was made at the processor; this one says the cutover's check is
            // what found it, and when Stripe actually paid it — which the
            // refund row, dated today, does not.
            $this->auditor->record('refund.reconciled', $order->fresh(), metadata: [
                'refund_id' => $refund->id,
                'gateway_reference' => $stripeRefund['id'],
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'tickets' => $refund->tickets()->count(),
                'refunded_in_stripe_at' => $when->toIso8601String(),
                'source' => 'legacy:reconcile',
            ]);

            $said[] = "recorded {$stripeRefund['id']} ({$refund->amount} {$refund->currency}, refunded in Stripe {$when->toDateString()})";
        }

        return $said;
    }

    /**
     * One Stripe refund, decided and written under the order's lock.
     *
     * @param  array{id: string, amount: int, currency: string, status: string, created: int}  $stripeRefund
     * @return array{0: ?Refund, 1: ?string} the refund written, or why not (null when it is already here)
     */
    private function recordOne(Order $order, array $stripeRefund): array
    {
        return DB::transaction(function () use ($order, $stripeRefund): array {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            // On any order, not only this one. A Stripe refund is money that
            // went back once; found already written against another order —
            // two imported sales on one payment — it is not written again.
            $already = Refund::query()
                ->where('gateway_reference', $stripeRefund['id'])
                ->first(['order_id']);

            if ($already !== null) {
                return $already->order_id === $locked->id
                    ? [null, null]
                    : [null, 'already recorded on order '.(Order::query()->whereKey($already->order_id)->value('reference') ?? $already->order_id)];
            }

            if (! in_array($locked->status, ['paid', 'partially_refunded'], true)) {
                return [null, "the order is {$locked->status} here, which a refund does not change"];
            }

            if ($stripeRefund['currency'] !== $locked->currency) {
                return [null, "refunded in {$stripeRefund['currency']}, order is in {$locked->currency}"];
            }

            // A refund somebody started here and Stripe has not answered yet.
            // It may be this very one; recording beside it could count it twice.
            if ($locked->refunds()->where('status', 'pending')->exists()) {
                return [null, 'a refund on this order is still pending here'];
            }

            $out = (int) $locked->refunds()->where('status', 'succeeded')->sum('amount');

            if ($stripeRefund['amount'] <= 0 || $out + $stripeRefund['amount'] > $locked->total_amount) {
                return [null, 'more than is left on the order here'];
            }

            // Null only when it finds nothing left to refund, which the checks
            // above have already ruled out; it writes nothing in that case.
            $refund = $this->refunds->recordMadeElsewhere($locked, $stripeRefund['amount'], $stripeRefund['id']);

            return $refund === null
                ? [null, 'nothing is left on the order here to refund']
                : [$refund, null];
        });
    }
}
