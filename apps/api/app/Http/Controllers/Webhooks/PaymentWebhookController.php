<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\Payments\FindsCheckouts;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Services\Checkout\Fulfiller;
use App\Services\Disputes\DisputeService;
use App\Services\Disputes\ProcessorEvidence;
use App\Services\Refunds\ProcessorRefunds;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only route by which an order becomes paid.
 *
 * The browser returning from a payment page proves nothing — it is a
 * navigation event the user can fabricate, replay, or never trigger at all. The
 * previous platform trusted exactly that, and took the ticket contents from the
 * client too, so a genuine one-dollar payment could be redeemed for any
 * quantity of anything. Honest buyers were bitten by the same design from the
 * other side: closing the tab after paying meant no tickets were ever issued.
 */
class PaymentWebhookController extends Controller
{
    /** Notices about money already taken: some of it going back, or disputed. */
    private const ABOUT_TAKEN_MONEY = [
        PaymentEvent::REFUNDED,
        PaymentEvent::DISPUTED,
        PaymentEvent::DISPUTE_LOST,
        PaymentEvent::DISPUTE_WON,
    ];

    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly Fulfiller $fulfiller,
        private readonly DisputeService $disputes,
        private readonly ProcessorRefunds $refunds,
    ) {}

    public function __invoke(Request $request, string $gateway): Response
    {
        $adapter = $this->gateways->named($gateway);

        // The raw body, not the parsed array. Signatures cover exact bytes, and
        // re-encoding JSON changes them.
        $payload = $request->getContent();

        $event = $adapter->parseWebhook($payload, $request->headers->all());

        if ($event === null) {
            // Covers both an invalid signature and an event we do not act on.
            // 202 either way: a gateway told something true, and whether we
            // cared is not its problem. Returning 4xx makes gateways retry and
            // eventually disable the endpoint.
            return response()->noContent(202);
        }

        // Gateways retry for days. Recording the event id makes a repeat
        // delivery a no-op rather than a second set of tickets.
        if (ProcessedWebhook::alreadyHandled($gateway, $event->eventId)) {
            return response()->noContent(200);
        }

        // Either identifier: the session the order was created against, or
        // the payment that session produced, which is what a dispute names.
        // Only among this processor's orders — a notice signed by Paystack is
        // no evidence about a Stripe order, whatever reference it carries.
        $order = $event->reference === '' ? null : Order::query()
            ->where('gateway', $gateway)
            ->where(fn ($query) => $query
                ->where('gateway_reference', $event->reference)
                ->orWhere('gateway_payment_reference', $event->reference))
            ->first();

        if (in_array($event->type, self::ABOUT_TAKEN_MONEY, true)) {
            try {
                $waiting = $this->stillWaitingForItsPayment($adapter, $gateway, $event, $order);
            } catch (Throwable $e) {
                Log::warning('A refund or dispute notice named a payment nothing here has heard of, and the processor could not be asked which checkout it came from. It will be sent again.', [
                    'gateway' => $gateway,
                    'reference' => $event->reference,
                    'exception' => $e->getMessage(),
                ]);

                return response()->noContent(503);
            }

            if ($waiting !== null) {
                Log::info('A refund or dispute notice arrived before the payment it is about. The processor will send it again.', [
                    'gateway' => $gateway,
                    'order' => $waiting->reference,
                    'event' => $event->eventId,
                    'type' => $event->type,
                ]);

                return response()->noContent(409);
            }
        }

        if ($order === null) {
            Log::warning('Webhook for an unknown order.', [
                'gateway' => $gateway,
                'reference' => $event->reference,
            ]);

            return response()->noContent(202);
        }

        // Claimed before it is acted on, in the same transaction. Two copies
        // of one notice arriving together both get past the check above; the
        // unique index lets only one of them claim it, and the other waits for
        // the first to finish and then finds nothing to do. Remembering it
        // afterwards, as this used to, let both act — a refund made in the
        // dashboard written down twice. If acting on it fails, the claim goes
        // with it, and the processor's retry is handled as the first.
        DB::transaction(function () use ($gateway, $event, $order) {
            if ($this->claim($gateway, $event->eventId)) {
                $this->apply($event, $order);
            }
        });

        return response()->noContent(200);
    }

    /**
     * The order a refund or dispute is about, if its payment has not landed yet.
     *
     * Processors do not promise to deliver in order. A payment notice that
     * failed — a deadlock, a deploy — is retried an hour later, and a refund
     * made in the dashboard meanwhile is announced first. Read then, it is
     * about an order still waiting to be paid: Stripe's names a payment the
     * order has not been told of yet, so it found no order at all and was
     * acknowledged and dropped. The payment then landed, tickets were issued
     * and the sale went on the organizer's balance, for money the buyer
     * already had back.
     *
     * So a notice like that is not acknowledged. The processor sends it
     * again later, by when the payment has been recorded and there is a sale
     * for it to be read against. Stripe is asked which checkout the payment
     * was made on, since that is all the order knows it by until then. An
     * order turned away after paying (Fulfiller::turnAway) has recorded its
     * payment, and its own refund's notices are read as they come.
     */
    private function stillWaitingForItsPayment(PaymentGateway $adapter, string $gateway, PaymentEvent $event, ?Order $order): ?Order
    {
        if ($order === null && $adapter instanceof FindsCheckouts && $event->reference !== '') {
            $checkout = $adapter->checkoutFor($event->reference);

            $order = $checkout === null ? null : Order::query()
                ->where('gateway', $gateway)
                ->where('gateway_reference', $checkout)
                ->first();
        }

        $waiting = $order !== null
            && in_array($order->status, ['pending', 'cancelled'], true)
            && $order->paid_at === null;

        return $waiting ? $order : null;
    }

    /**
     * Whether this delivery is the one to act on the notice.
     *
     * A notice with no id of any kind cannot be told from its repeats, so
     * each delivery is acted on; everything it leads to has to cope with that
     * anyway, because a processor can send the same news under two ids.
     */
    private function claim(string $gateway, string $eventId): bool
    {
        if ($eventId === '') {
            return true;
        }

        return ProcessedWebhook::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'gateway' => $gateway,
            'event_id' => $eventId,
            'processed_at' => now(),
        ]) === 1;
    }

    /**
     * What the notice means for the order.
     *
     * Nothing here writes a status straight over the order's. A paid order
     * stays paid whatever arrives after it (Order::mayBecome), refunds are
     * counted from the refunds on record rather than taken from the notice
     * (ProcessorRefunds), and tickets are only ever issued by the Fulfiller.
     */
    private function apply(PaymentEvent $event, Order $order): void
    {
        match ($event->type) {
            PaymentEvent::PAID => $this->markPaid($event, $order),
            PaymentEvent::FAILED => $this->failed($order, $event),
            PaymentEvent::EXPIRED => $this->expired($order, $event),
            PaymentEvent::REFUNDED => $this->refunds->heard($order, $event),
            PaymentEvent::DISPUTED => $this->disputes->opened($order, $event),
            PaymentEvent::DISPUTE_LOST => $this->disputes->closed($order, $event, lost: true),
            PaymentEvent::DISPUTE_WON => $this->disputes->closed($order, $event, lost: false),
            default => null,
        };
    }

    /**
     * Move the order along, if the notice may move it there.
     *
     * Under the order's lock, so it is decided against what the order is now
     * and not what it was when the notice was read. The same status twice is
     * nothing to do; a move backwards is refused and written to the log, where
     * a processor sending things out of order will show up.
     *
     * @param  (callable(Order): void)|null  $also  anything that goes with the move, done under the same lock
     */
    private function move(Order $order, string $status, PaymentEvent $event, ?callable $also = null): void
    {
        DB::transaction(function () use ($order, $status, $event, $also) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status === $status) {
                return;
            }

            if (! $locked->mayBecome($status)) {
                Log::info('A payment notice would have moved an order backwards. It was not moved.', [
                    'order' => $locked->reference,
                    'from' => $locked->status,
                    'to' => $status,
                    'event' => $event->eventId,
                ]);

                return;
            }

            $locked->update(['status' => $status]);

            if ($also !== null) {
                $also($locked);
            }
        });
    }

    /**
     * The payment page closed with nobody having paid on it.
     *
     * The order is closed, and its stock goes back on sale now rather than
     * when the hold runs out. The hold outlasts the page on purpose, to cover
     * a payment made in its last seconds — and this says there was none.
     *
     * Only a pending order (Order::mayBecome). A notice that arrives out of
     * turn must not close an order somebody has paid for.
     */
    private function expired(Order $order, PaymentEvent $event): void
    {
        $this->move($order, 'cancelled', $event, fn (Order $locked) => $locked->holds()->delete());
    }

    /**
     * The payment did not go through, and will not now: a bank debit that
     * bounced, a lender that said no after the page had closed.
     *
     * The order is closed as failed and its places go back on sale at once,
     * as an expired page's do; nothing leaves failed (Order::mayBecome), so
     * there is nothing for them to be held for. Only a pending order: one
     * closed as abandoned already let its places go, and a paid one stays
     * paid whatever arrives after it.
     */
    private function failed(Order $order, PaymentEvent $event): void
    {
        $this->move($order, 'failed', $event, fn (Order $locked) => $locked->holds()->delete());
    }

    private function markPaid(PaymentEvent $event, Order $order): void
    {
        // A paid event for the wrong amount is not fulfilment, it is an
        // incident. Fulfilling anyway would mean the ledger records revenue
        // that was never collected.
        if (! $event->matches($order->total_amount, $order->currency)) {
            Log::alert('Payment amount does not match the order.', [
                'order' => $order->reference,
                'expected' => $order->total_amount.' '.$order->currency,
                'received' => $event->amountMinorUnits.' '.$event->currency,
            ]);

            return;
        }

        // Kept before fulfilment, because everything the processor says
        // afterwards — a refund, a dispute — is about this and not about the
        // checkout session the order was created against.
        if ($event->paymentReference !== null && $order->gateway_payment_reference === null) {
            $order->forceFill(['gateway_payment_reference' => $event->paymentReference])->save();
        }

        $this->fulfiller->fulfil($order);

        // The processor's own record of this payment, for a dispute later:
        // noted once this has committed, and fetched by the next sweep
        // (ProcessorEvidence). Nothing about it can hold up the tickets above
        // or fail this notice; a note that cannot be written is reported, and
        // the sweep finds the order anyway.
        DB::afterCommit(fn () => rescue(fn () => app(ProcessorEvidence::class)->expect($order->fresh() ?? $order, $event)));
    }
}
