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
                $request->input('access_code'),
                $request->addOnQuantities(),
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
                accessInput: $request->input('access_code'),
                answers: $request->orderAnswers(),
                attendees: $request->attendees(),
                addOns: $request->addOnQuantities(),
            );
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        // Which terms the buyer agreed to, and when, kept on the order the way
        // its prices are. Before anything is issued for it.
        $request->recordAcceptance($order);

        if ($request->boolean('embedded')) {
            $order->forceFill(['embedded' => true])->save();
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
                /*
                 * Where the gateway drops the buyer, and both of these were
                 * wrong.
                 *
                 * Success pointed at the API's signed orders route, which
                 * returns JSON — somebody who had just paid landed on a wall of
                 * braces. It now goes to the order screen on the public site,
                 * which polls until the webhook settles the order and can say
                 * "confirming your payment" in the meantime. The return itself
                 * still proves nothing; the signed webhook does.
                 *
                 * Cancel read config('app.frontend_url'), which is not defined
                 * anywhere — so it fell back to the API's own host and sent
                 * somebody who changed their mind to a 404 instead of back to
                 * the event they were looking at.
                 */
                successUrl: $this->site()."/order/{$order->reference}",
                cancelUrl: $this->site()."/{$event->slug}",
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

        // Taken off sale because its organizer is suspended: a buyer part-way
        // through is told that, rather than that the event does not exist.
        if ($event === null && Event::query()->where('slug', $slug)->whereNotNull('unpublished_by_suspension_at')->exists()) {
            abort(422, 'This organizer is not selling tickets on myFiesta at the moment. Tickets already bought are not affected.');
        }

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
                // Which of the two this line is, said rather than inferred
                // from a null: a client counting people to ask questions of
                // must not count bottles.
                'kind' => $line->isTicket() ? 'ticket' : 'add_on',
                'ticket_type_id' => $line->ticketType?->id,
                'add_on_id' => $line->addOn?->id,
                'name' => $line->name(),
                'quantity' => $line->quantity,
                'unit_price' => $money($line->unitPrice),
                'line_total' => $money($line->lineTotal),
                // Which lines the code took money off, so a code for General
                // does not read as having discounted the VIP ticket too.
                'discount' => $money($line->discount),
            ], $quote->lines),
            'subtotal' => $money($quote->subtotal),
            'discount' => $money($quote->discount),
            'tax' => $money($quote->tax),
            // Itemised for the buyer rather than folded into the total. A fee
            // that only appears as a larger number at the end is the thing
            // people complain about; one on its own line is one they accept.
            'service_charge' => $money($quote->serviceCharge),
            // What the organizer earns. Returned so the console can show a
            // payout without recomputing a rate it would then have to keep in
            // step with the server's.
            'net_revenue' => $money($quote->netRevenue),
            'total' => $money($quote->total),
            // So the client can label it honestly rather than guessing whether
            // tax was added or was already inside the price.
            'tax_inclusive' => (bool) $quote->taxRate?->inclusive,
            // "GST + QST" in Quebec while QST is collected, not just "GST".
            'tax_label' => $quote->taxLabel(),
            // Each tax on its own, and how much of the service charge is tax,
            // for a client that shows them the way the receipt will.
            'tax_lines' => array_map(fn ($line) => [
                'name' => $line->name,
                'rate' => $line->percent(),
                'on' => $line->on,
                'included' => $line->inclusive,
                'amount' => $money($line->amount),
            ], $quote->taxLines),
            'service_charge_tax' => $money($quote->serviceChargeTax ?? Money::zero($quote->currency())),
            'code_applied' => $quote->code?->code,
            'access_code_applied' => $quote->accessCode?->code,
            // Null for a code on every ticket.
            'code_applies_to' => $quote->code && $quote->code->ticketTypes()->exists()
                ? $quote->code->ticketTypes()->orderBy('sort_order')->pluck('name')->all()
                : null,
            'requires_payment' => $quote->requiresPayment(),
        ];
    }

    /**
     * The public site, which is not this application.
     *
     * The API serves no pages, so every URL handed to a payment gateway has to
     * name the site explicitly. Falling back to app.url — as the cancel URL
     * used to — sends buyers to the API host, where nothing they want exists.
     */
    private function site(): string
    {
        return rtrim(config('app.public_url'), '/');
    }
}
