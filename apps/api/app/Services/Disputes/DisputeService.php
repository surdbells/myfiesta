<?php

namespace App\Services\Disputes;

use App\Contracts\Payments\PaymentEvent;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Integrations\Payloads;
use App\Services\Integrations\Webhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens when a bank takes the money back.
 *
 * Opening a dispute changes nothing about the tickets. A dispute is a claim,
 * not a verdict — plenty are withdrawn or decided for the organizer — and
 * voiding somebody's ticket on the strength of an unproven claim would turn a
 * bank's paperwork into a person being turned away at a door.
 *
 * Losing one does change things. The money is gone, so the ledger says so, and
 * the tickets it paid for stop working: a chargeback that leaves a working
 * ticket behind is the whole of ticket fraud in one step, and the organizer
 * would be paying for the drinks as well.
 *
 * Neither outcome is a refund. A refund is the organizer deciding; this is a
 * bank deciding, and the two are told apart everywhere they are counted.
 */
class DisputeService
{
    /** A dispute has been raised against an order. */
    public function opened(Order $order, PaymentEvent $event): Dispute
    {
        $details = Details::from($event);

        $dispute = DB::transaction(function () use ($order, $details) {
            $dispute = Dispute::updateOrCreate(
                ['gateway' => $order->gateway ?? 'unknown', 'gateway_reference' => $details->reference],
                [
                    'order_id' => $order->id,
                    'organization_id' => $order->organization_id,
                    'event_id' => $order->event_id,
                    'amount' => $details->amount ?: $order->total_amount,
                    'currency' => $details->currency ?: $order->currency,
                    'reason' => $details->reason,
                    'status' => 'open',
                    'opened_at' => now(),
                    'evidence_due_at' => $details->evidenceDueAt,
                ],
            );

            $order->forceFill(['disputed_at' => now()])->save();

            // Whoever asked to hear about this organization's orders. A
            // promoter with their own dashboard should not learn about a
            // chargeback from a statement six weeks later.
            app(Webhooks::class)->emit($order->organization_id, 'order.disputed', [
                ...app(Payloads::class)->order($order->fresh()),
                'dispute' => [
                    'amount' => ['amount' => (int) $dispute->amount, 'currency' => $dispute->currency],
                    'reason' => $dispute->reason,
                    'evidence_due_at' => $dispute->evidence_due_at?->toIso8601String(),
                ],
            ]);

            return $dispute;
        });

        Log::alert('Payment disputed.', [
            'order' => $order->reference,
            'organization' => $order->organization_id,
            'amount' => $dispute->amount.' '.$dispute->currency,
            'reason' => $dispute->reason,
        ]);

        return $dispute;
    }

    /**
     * The processor has decided.
     *
     * Idempotent: a repeated delivery about a dispute already closed does
     * nothing, so the ledger cannot be written twice for one chargeback.
     */
    public function closed(Order $order, PaymentEvent $event, bool $lost): ?Dispute
    {
        $details = Details::from($event);

        $dispute = Dispute::where('gateway_reference', $details->reference)->first()
            ?? Dispute::where('order_id', $order->id)->where('status', 'open')->latest('opened_at')->first();

        if ($dispute === null || ! $dispute->isOpen()) {
            return $dispute;
        }

        return DB::transaction(function () use ($dispute, $order, $lost) {
            $dispute->update([
                'status' => $lost ? 'lost' : 'won',
                'closed_at' => now(),
            ]);

            if (! $lost) {
                // Kept on the order that it happened at all: the next person
                // looking at this buyer should see it.
                return $dispute;
            }

            $this->takeBack($order, $dispute);

            return $dispute;
        });
    }

    /**
     * The money is gone and the tickets go with it.
     *
     * The order's status is left alone. It was paid, and then a bank took the
     * money back — calling that 'refunded' would put it in with the refunds an
     * organizer chose to give, which is the one place it must never be counted.
     * What says so is disputed_at, the dispute row, and the ledger.
     *
     * The ledger entry is negative and of its own type, so an organizer
     * reading their balance sees a chargeback rather than a refund they never
     * agreed to — and settlements, which pay from this ledger, stop paying for
     * a sale that was taken back.
     *
     * It takes back what the organizer is still credited with for this order,
     * which is what the order's own ledger entries add up to — not the order's
     * net revenue. The two differ whenever money has already left the balance
     * some other way: an order refunded in part or in full has its reversals
     * in the ledger already, and a payment that arrived for places that had
     * gone was refunded without a sale ever being written. Taking the net
     * revenue off again charged the organizer for money they no longer had,
     * or never had. Nothing is written when nothing is left; the tickets are
     * voided either way.
     */
    private function takeBack(Order $order, Dispute $dispute): void
    {
        // Under the order's lock, the one a refund settles under, so a refund
        // finishing at this moment is either counted here or comes after.
        Order::query()->whereKey($order->id)->lockForUpdate()->first();

        $stillCredited = (int) LedgerEntry::query()->where('order_id', $order->id)->sum('amount');

        if ($stillCredited > 0) {
            LedgerEntry::create([
                'organization_id' => $order->organization_id,
                'event_id' => $order->event_id,
                'order_id' => $order->id,
                'type' => 'chargeback',
                'amount' => -$stillCredited,
                'currency' => $order->currency,
                'occurred_at' => now(),
                'reason' => "Chargeback on order {$order->reference}"
                    .($dispute->reason ? " — {$dispute->reason}" : ''),
            ]);
        } else {
            Log::info('A lost dispute took back nothing from the organizer: nothing was left credited for the order.', [
                'order' => $order->reference,
                'dispute' => $dispute->gateway_reference,
                'credited' => $stillCredited,
            ]);
        }

        Ticket::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['valid', 'checked_in'])
            ->update(['status' => 'void', 'updated_at' => now()]);
    }
}
