<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\QuoteRequest;
use App\Models\Event;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Checkout\Quote;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Pricing and buying.
 *
 * Both endpoints take quantities and nothing else that costs money. The quote
 * is free to call on every cart change — it takes no locks and writes nothing —
 * while creating an order reserves stock and opens a payment.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly Fulfiller $fulfiller,
    ) {}

    public function quote(QuoteRequest $request, string $slug): JsonResponse
    {
        $event = $this->sellableEvent($slug);

        try {
            $quote = $this->checkout->quote(
                $event,
                $request->quantities(),
                $request->input('code'),
                $request->input('ref'),
            );
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($this->present($quote));
    }

    public function store(CreateOrderRequest $request, string $slug): JsonResponse
    {
        $event = $this->sellableEvent($slug);

        try {
            $order = $this->checkout->reserve(
                event: $event,
                quantities: $request->quantities(),
                buyerEmail: $request->input('buyer.email'),
                buyerName: $request->input('buyer.name'),
                codeInput: $request->input('code'),
                refSlug: $request->input('ref'),
                user: $request->user(),
                buyerPhone: $request->input('buyer.phone'),
            );
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        // Nothing to charge — a comp, a full-value code, a free event. There is
        // no gateway to talk to, so the tickets are issued now.
        if (! $order->requiresPayment()) {
            $this->fulfiller->fulfilFree($order);

            return response()->json([
                'reference' => $order->reference,
                'status' => 'paid',
                'payment' => null,
            ], 201);
        }

        $gateway = $this->gateways->forCurrency($order->currency);

        try {
            $session = $gateway->createCheckout($order, new CheckoutOptions(
                successUrl: URL::temporarySignedRoute('orders.show', now()->addDays(90), ['order' => $order->id]),
                cancelUrl: config('app.frontend_url', config('app.url'))."/{$event->slug}",
                idempotencyKey: $order->idempotency_key,
            ));
        } catch (Throwable $e) {
            // A processor's own error text is written for us, not for the
            // person buying a ticket — Stripe's misconfiguration message names
            // our API key, and a buyer seeing it learns something about our
            // setup and nothing about their order.
            Log::error('Could not open a payment session.', [
                'order' => $order->reference,
                'gateway' => $gateway->name(),
                'error' => $e->getMessage(),
            ]);

            // The order and its hold stay. Their stock is still theirs for the
            // hold window, so retrying costs them nothing and they are not
            // pushed to the back of a queue for our failure.
            return response()->json([
                'message' => 'We could not reach the payment provider. '
                    .'Nothing has been charged — please try again in a moment.',
                'reference' => $order->reference,
            ], 502);
        }

        $order->update([
            'gateway' => $gateway->name(),
            'gateway_reference' => $session->reference,
        ]);

        return response()->json([
            'reference' => $order->reference,
            'status' => 'pending',
            'payment' => [
                'gateway' => $gateway->name(),
                'redirect_url' => $session->redirectUrl,
                'expires_at' => $session->expiresAt,
            ],
        ], 201);
    }

    private function sellableEvent(string $slug): Event
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('kind', 'ticketed')
            ->first();

        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        return $event;
    }

    private function present(Quote $quote): array
    {
        $money = fn (Money $m) => [
            'amount' => $m->amount,
            'currency' => $m->currency,
        ];

        return [
            'lines' => array_map(fn ($line) => [
                'ticket_type_id' => $line->ticketType->id,
                'name' => $line->ticketType->name,
                'quantity' => $line->quantity,
                'unit_price' => $money($line->unitPrice),
                'line_total' => $money($line->lineTotal),
            ], $quote->lines),
            'subtotal' => $money($quote->subtotal),
            'discount' => $money($quote->discount),
            'tax' => $money($quote->tax),
            'total' => $money($quote->total),
            // So the client can label it honestly rather than guessing whether
            // tax was added or was already inside the price.
            'tax_inclusive' => (bool) $quote->taxRate?->inclusive,
            'tax_label' => $quote->taxRate?->name,
            'code_applied' => $quote->code?->code,
            'requires_payment' => $quote->requiresPayment(),
        ];
    }
}
