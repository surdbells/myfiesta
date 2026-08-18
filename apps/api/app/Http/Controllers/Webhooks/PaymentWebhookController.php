<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Services\Checkout\Fulfiller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly Fulfiller $fulfiller,
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

        $order = Order::where('gateway_reference', $event->reference)->first();

        if ($order === null) {
            Log::warning('Webhook for an unknown order.', [
                'gateway' => $gateway,
                'reference' => $event->reference,
            ]);

            return response()->noContent(202);
        }

        $this->apply($event, $order);

        ProcessedWebhook::remember($gateway, $event->eventId);

        return response()->noContent(200);
    }

    private function apply(PaymentEvent $event, Order $order): void
    {
        match ($event->type) {
            PaymentEvent::PAID => $this->markPaid($event, $order),
            PaymentEvent::FAILED => $order->update(['status' => 'failed']),
            PaymentEvent::EXPIRED => $order->update(['status' => 'cancelled']),
            PaymentEvent::REFUNDED => $order->update([
                'status' => 'refunded',
                'refunded_at' => now(),
            ]),
            PaymentEvent::DISPUTED => Log::alert('Payment disputed.', [
                'order' => $order->reference,
            ]),
            default => null,
        };
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

        $this->fulfiller->fulfil($order);
    }
}
