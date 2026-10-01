<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Messaging\MessageSender;
use App\Services\Payments\PaidLaterTooLongAgo;
use App\Services\Payments\PayLater;
use App\Services\Refunds\RefundRefused;
use App\Services\Refunds\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Calling an event off.
 *
 * Cancelling is not a status change. It is five things that must all happen,
 * and the reason this is a service rather than a controller method is that
 * doing four of them is worse than doing none — an event marked cancelled whose
 * reminders keep firing tells everybody holding a ticket to turn up to a night
 * that is not happening.
 *
 *   1. Stop selling. The status is what checkout already reads.
 *   2. Stop the reminders that would otherwise still go out.
 *   3. Tell everybody holding a ticket, as a message that overrides their
 *      opt-out — this is the definition of news somebody needs before they
 *      travel.
 *   4. Give the money back, if asked to.
 *   5. Record who did it and why.
 *
 * Refunding is a choice rather than automatic, and the choice defaults to yes.
 * It is the organizer's money and their obligation under the terms, so the
 * platform should not move it silently — but a cancellation screen whose
 * default is "keep their money" is not one this platform should ship either.
 */
class EventCanceller
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly MessageSender $messages,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  bool  $refund  refund every paid order, or leave them to the organizer
     * @return array{refunded: int, failed: int, left_for_support: int, notified: int}
     */
    public function cancel(Event $event, User $by, string $reason, bool $refund = true): array
    {
        $status = EventStatus::from($event->status);

        if (! $status->canBecome(EventStatus::Cancelled)) {
            throw new RuntimeException(
                $status === EventStatus::Cancelled
                    ? 'This event is already cancelled.'
                    : 'Only an event that is on sale can be cancelled.'
            );
        }

        /*
         * The status moves first, in its own transaction.
         *
         * Everything after this is slow and can fail per-order. Taking the
         * event off sale is the one step that must not be left undone, and
         * holding a transaction open across a payment provider's API is how a
         * database ends up with a lock waiting on somebody else's network.
         */
        DB::transaction(function () use ($event, $by, $reason) {
            $event->update([
                'status' => EventStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => $by->id,
            ]);

            // Anything still queued would otherwise fire on schedule. The
            // dispatcher also filters on published, so this is belt and braces
            // — and the belt is the one an organizer can see in the console.
            $event->reminders()
                ->whereIn('status', ['scheduled', 'sending'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        });

        $notified = $this->tellTicketHolders($event, $by, $reason);

        $outcome = $refund
            ? $this->refundEverybody($event, $by, $reason)
            : ['refunded' => 0, 'failed' => 0, 'left_for_support' => 0];

        // Recorded after the work, so the entry says what actually happened
        // rather than what was intended — including refunds a provider refused.
        $this->auditor->record('event.cancelled', $event, $by, metadata: [
            'reason' => $reason,
            'refund_requested' => $refund,
            'notified' => $notified,
        ] + $outcome);

        return $outcome + ['notified' => $notified];
    }

    /**
     * The message that reaches everybody, opt-out or not.
     *
     * Sent before the refunds rather than after. A refund arriving with no
     * explanation reads as a mistake, and the person most likely to be checking
     * their bank at that moment is the one who was travelling.
     */
    private function tellTicketHolders(Event $event, User $by, string $reason): int
    {
        $message = $event->messages()->create([
            'sent_by' => $by->id,
            'subject' => 'This event has been cancelled',
            'body' => trim($reason),
            // Overrides the reminder opt-out, and carries no unsubscribe link.
            // Somebody who turned off event emails still needs to not travel.
            'important' => true,
            'status' => 'queued',
        ]);

        return $this->messages->send($message);
    }

    /**
     * Give the money back, one order at a time.
     *
     * Per-order rather than in one sweep, because a payment provider can refuse
     * a single refund — an expired card, a closed account, a dispute already
     * open — and one failure must not stop the other four hundred. Failures are
     * logged and counted; the orders screen still shows them as refundable, so
     * an organizer can see exactly which ones need a human.
     */
    private function refundEverybody(Event $event, User $by, string $reason): array
    {
        $refunded = 0;
        $failed = 0;
        $leftForSupport = 0;

        $orders = $event->orders()
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->cursor();

        foreach ($orders as $order) {
            try {
                $this->refunds->refund(
                    $order,
                    null, // everything still refundable on this order
                    $by,
                    "Event cancelled: {$reason}",
                );

                $refunded++;
            } catch (PaidLaterTooLongAgo $e) {
                // Paid with Klarna or Affirm longer ago than the lender takes
                // money back for. Not "nothing left to refund", as the refusal
                // below usually is, and not something the organizer can do by
                // hand either: money support returns another way. Counted on
                // its own so the organizer is told to send them to support,
                // and already on the order's trail (RefundService::refund).
                $leftForSupport++;
            } catch (RefundRefused $e) {
                // Nothing left to refund on this order, usually. Not a failure
                // worth alarming anybody about.
                Log::info('Refund skipped while cancelling an event', [
                    'event' => $event->id,
                    'order' => $order->reference,
                    'reason' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $failed++;

                Log::error('Refund failed while cancelling an event', [
                    'event' => $event->id,
                    'order' => $order->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['refunded' => $refunded, 'failed' => $failed, 'left_for_support' => $leftForSupport];
    }

    /**
     * What cancelling would cost, so it can be shown before the button.
     *
     * The outstanding figure is total minus what has already been refunded,
     * summed the same way the orders screen sums it — from succeeded refunds,
     * not from a column on the order. There is no stored refunded_amount, and
     * adding one would be a second answer to a question the ledger already
     * answers.
     */
    public function preview(Event $event): array
    {
        $orders = $event->orders()
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->withSum(
                ['refunds as refunded_amount' => fn ($q) => $q->where('status', 'succeeded')],
                'amount',
            )
            ->get();

        $outstanding = $orders->sum(
            fn (Order $o) => max(0, $o->total_amount - (int) $o->refunded_amount),
        );

        return [
            'ticket_holders' => $event->tickets()
                ->whereIn('status', ['valid', 'checked_in'])
                ->distinct('owner_email')
                ->count('owner_email'),
            'orders_to_refund' => $orders->count(),
            'refund_total' => [
                'amount' => (int) $outstanding,
                'currency' => $event->currency,
            ],
            // Of those, the ones paid with Klarna or Affirm too long ago for
            // the money to go back that way: refused here, and returned by
            // support another way (PayLater::refundRefusal).
            'orders_to_refund_elsewhere' => app(PayLater::class)->pastRefundWindow($orders),
        ];
    }
}
