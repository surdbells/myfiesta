<?php

namespace App\Services\Refunds;

use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\RefundNotice;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Refund;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What a processor tells us about refunds, matched against what we know.
 *
 * Every refund on the account is announced: the ones we asked for and the
 * ones somebody made in the Stripe or Paystack dashboard. Until now the
 * announcement set the order to refunded and stopped there — so a partial
 * refund of ours marked the whole order refunded, and one made in the
 * dashboard left every ticket working and the organizer's balance counting a
 * sale that had gone back.
 *
 * So each notice is matched first. One that names a refund of ours confirms
 * it, and settles it if it was still waiting for an answer. One that does not
 * is money that left some other way, and is written down as a refund in its
 * own right (RefundService::recordMadeElsewhere) — once, however many times it
 * is announced:
 *
 *   A running total (Stripe's charge.refunded) is compared with everything
 *   already on record, ours included, and only the difference is new. Read
 *   twice, it finds no difference the second time.
 *
 *   A single refund (Paystack's refund.processed) is matched against our
 *   refunds of the same amount that no notice has claimed yet. Only if there
 *   is none is it somebody else's. A single failure that names nothing of
 *   ours changes nothing, but is put in front of a person when it could be
 *   one of ours (failedWithoutAName).
 *
 * Everything happens under the order's lock, so two notices for the same
 * order are read one after the other and the second sees what the first did.
 */
class ProcessorRefunds
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly Auditor $auditor,
    ) {}

    public function heard(Order $order, PaymentEvent $event): void
    {
        $notice = $event->refund;

        if ($notice === null) {
            return;
        }

        if ($event->currency !== '' && strtoupper($event->currency) !== strtoupper($order->currency)) {
            Log::alert('A refund notice is in a different currency from its order. Nothing was recorded.', [
                'order' => $order->reference,
                'order_currency' => $order->currency,
                'notice_currency' => $event->currency,
            ]);

            return;
        }

        $this->reconcile($order, $notice);
    }

    /**
     * Read what the processor says against what is on record for the order.
     *
     * An announcement, from heard(); or the processor's answer when it was
     * asked for its running total after refusing a refund of ours
     * (RefundService::afterRefusal). Both are read the same way.
     */
    public function reconcile(Order $order, RefundNotice $notice): void
    {
        DB::transaction(function () use ($order, $notice) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($notice->isRunningTotal()) {
                $this->catchUp($locked, $notice);

                return;
            }

            $this->one($locked, $notice);
        });
    }

    /**
     * A running total: confirm the refunds it lists that are ours, then
     * record whatever it counts that we have not.
     *
     * Pending refunds of ours count as on record. The notice can arrive
     * between our request reaching the processor and its answer reaching us,
     * and in that moment the refund is ours and merely unfinished.
     */
    private function catchUp(Order $order, RefundNotice $notice): void
    {
        foreach ($notice->parts as $part) {
            $mine = $this->named($order, $part);

            if ($mine !== null) {
                $this->confirm($order, $mine, $part);
            }
        }

        $onRecord = (int) Refund::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['pending', 'succeeded'])
            ->sum('amount');

        $missing = (int) $notice->totalRefundedMinorUnits - $onRecord;

        if ($missing <= 0) {
            return;
        }

        // When the notice lists its refunds, the one it is about can be named.
        $known = Refund::query()->where('order_id', $order->id)->whereNotNull('gateway_reference')->pluck('gateway_reference')->all();

        $reference = collect($notice->parts)
            ->first(fn (RefundNotice $part) => $part->ourReference === null
                && $part->status === RefundNotice::SUCCEEDED
                && $part->amountMinorUnits === $missing
                && ! in_array($part->processorReference, $known, true))
            ?->processorReference;

        $this->madeElsewhere($order, $missing, $reference);
    }

    /** A notice about one refund. */
    private function one(Order $order, RefundNotice $notice): void
    {
        $mine = $this->named($order, $notice);

        if ($mine !== null) {
            $this->confirm($order, $mine, $notice);

            return;
        }

        // Tagged as ours, and nothing of ours has that tag. Another copy of
        // the platform sharing the processor account — staging, usually —
        // and not a refund of anything here.
        if ($notice->ourReference !== null) {
            Log::info('A refund notice names a refund this platform has no record of. Ignored.', [
                'order' => $order->reference,
                'our_reference' => $notice->ourReference,
            ]);

            return;
        }

        if ($notice->status === RefundNotice::FAILED) {
            $this->failedWithoutAName($order, $notice);

            return;
        }

        // Only money that has actually gone back is written down, or matched
        // by amount alone. One still waiting will announce itself again when
        // it is done.
        if ($notice->status !== RefundNotice::SUCCEEDED || ! $notice->amountMinorUnits) {
            return;
        }

        $mine = $this->unclaimedOfTheSameAmount($order, $notice);

        if ($mine !== null) {
            $this->confirm($order, $mine, $notice);

            return;
        }

        $this->madeElsewhere($order, $notice->amountMinorUnits, $notice->processorReference);
    }

    /**
     * A refund failed at the processor, and the notice does not say whose.
     *
     * Paystack's refund.failed is like this. It names the refund by a
     * reference our own request was never answered with, and carries no note
     * of ours. It is not acted on: read as ours, it would call a refund
     * failed that may have paid, and failed is the word that gets a refund
     * sent again.
     *
     * Nor is it dropped. Paystack says yes to a refund when it queues it, so
     * a refund of ours is recorded as done straight away — tickets stopped,
     * the organizer's balance reduced. If this failure is that refund, the
     * buyer has not been paid and nothing else here is going to notice. So a
     * refund of ours for the same amount, that no notice has confirmed, is
     * put in front of a person as the one it may be. One that matches nothing
     * of ours is somebody's dashboard refund that moved no money, and is
     * written to the log.
     */
    private function failedWithoutAName(Order $order, RefundNotice $notice): void
    {
        $maybe = $notice->amountMinorUnits ? $this->unclaimedOfTheSameAmount($order, $notice) : null;

        if ($maybe === null) {
            Log::warning('The payment processor reports a failed refund that matches no refund of ours. Nothing was recorded.', [
                'order' => $order->reference,
                'amount' => $notice->amountMinorUnits,
                'processor_reference' => $notice->processorReference,
            ]);

            return;
        }

        Log::alert('The payment processor reports a failed refund of the same amount as one of ours, without saying which. If it was ours, the money has not reached the buyer.', [
            'order' => $order->reference,
            'refund_id' => $maybe->id,
            'refund_status' => $maybe->status,
            'processor_reference' => $notice->processorReference,
        ]);

        $this->auditor->record('refund.failed_at_processor', $order, metadata: [
            'refund_id' => $maybe->id,
            'refund_status' => $maybe->status,
            'amount' => $maybe->amount,
            'currency' => $maybe->currency,
            'matched_by' => 'amount',
            'processor_reference' => $notice->processorReference,
        ]);
    }

    /**
     * Write down money that went back without us, unless a dispute explains it.
     *
     * A chargeback the organizer loses, or accepts, is paid back to the buyer
     * by the processor — Paystack does it as a refund, and announces it like
     * any other. The dispute has already taken that money off the balance and
     * stopped the tickets (DisputeService), so recording it here as well would
     * take it off twice. While a dispute is open the refund is most likely the
     * dispute being settled, and the same is true. So on a disputed order it
     * is left for a person, with everything they need to decide, rather than
     * guessed at in either direction. A dispute the organizer won took nothing,
     * and a refund after it is an ordinary one.
     */
    private function madeElsewhere(Order $order, int $amount, ?string $processorReference): void
    {
        $dispute = Dispute::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['open', 'lost'])
            ->latest('opened_at')
            ->first();

        if ($dispute === null) {
            $this->refunds->recordMadeElsewhere($order, $amount, $processorReference);

            return;
        }

        Log::alert('Money went back at the payment processor on a disputed order. It was not recorded as a refund; a person has to decide whether the dispute already counts it.', [
            'order' => $order->reference,
            'dispute' => $dispute->id,
            'dispute_status' => $dispute->status,
            'amount' => $amount,
            'processor_reference' => $processorReference,
        ]);

        $this->auditor->record('refund.made_elsewhere_while_disputed', $order, metadata: [
            'dispute_id' => $dispute->id,
            'dispute_status' => $dispute->status,
            'amount' => $amount,
            'currency' => $order->currency,
            'gateway' => $order->gateway,
            'processor_reference' => $processorReference,
        ]);
    }

    /** The refund of ours a notice names, by our tag or by the processor's id. */
    private function named(Order $order, RefundNotice $notice): ?Refund
    {
        $refunds = Refund::query()->where('order_id', $order->id);

        if ($notice->ourReference !== null) {
            return Str::isUuid($notice->ourReference)
                ? $refunds->whereKey($notice->ourReference)->first()
                : null;
        }

        if (filled($notice->processorReference)) {
            return $refunds->where('gateway_reference', $notice->processorReference)->first();
        }

        return null;
    }

    /**
     * Ours, by amount, when the notice gives nothing better to go on.
     *
     * The oldest refund of ours for that amount that no notice has claimed.
     * Claimed ones are skipped, so two refunds of the same amount — one ours,
     * one made in the dashboard — are read as two, not as the same one twice.
     */
    private function unclaimedOfTheSameAmount(Order $order, RefundNotice $notice): ?Refund
    {
        if (! $notice->amountMinorUnits) {
            return null;
        }

        return Refund::query()
            ->where('order_id', $order->id)
            ->where('source', Refund::FROM_PLATFORM)
            ->whereIn('status', ['pending', 'succeeded'])
            ->whereNull('confirmed_at')
            ->where('amount', $notice->amountMinorUnits)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * The processor's word on a refund of ours.
     *
     * A refund still waiting is settled by it. One already settled is only
     * checked against it, and a disagreement is said out loud rather than
     * acted on: undoing a refund means un-voiding tickets and rewriting a
     * ledger, and that is a person's decision.
     */
    private function confirm(Order $order, Refund $refund, RefundNotice $notice): void
    {
        if ($notice->status === RefundNotice::PENDING) {
            return;
        }

        if ($refund->status === 'pending') {
            $refund = $this->refunds->settleFromProcessor($refund, $notice);
        } elseif ($refund->status === 'succeeded' && $notice->status === RefundNotice::FAILED) {
            Log::alert('The payment processor says a refund recorded as done has failed. The money may not have reached the buyer.', [
                'order' => $order->reference,
                'refund_id' => $refund->id,
            ]);

            $this->auditor->record('refund.failed_at_processor', $order, metadata: [
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
            ]);
        } elseif ($refund->status === 'failed' && $notice->status === RefundNotice::SUCCEEDED) {
            Log::alert('The payment processor says a refund recorded as failed went through. The buyer may have been paid twice.', [
                'order' => $order->reference,
                'refund_id' => $refund->id,
            ]);

            $this->auditor->record('refund.succeeded_at_processor', $order, metadata: [
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
            ]);
        }

        if ($refund->confirmed_at === null) {
            $refund->forceFill(['confirmed_at' => now()])->save();
        }
    }
}
