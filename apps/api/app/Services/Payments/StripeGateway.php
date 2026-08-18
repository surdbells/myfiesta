<?php

namespace App\Services\Payments;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\RefundResult;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe, for every currency except naira.
 *
 * Talks to Stripe's REST API directly rather than through Cashier. Cashier is
 * built around subscriptions and a billable model; a ticket sale is a one-shot
 * charge against an order that may have no account behind it at all, since
 * guest checkout is the primary path. Using it here would mean adopting its
 * shape without its benefits.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function supports(string $currency): bool
    {
        // Naira goes to Paystack: card penetration in Nigeria is low, and
        // Paystack carries bank transfer, USSD, and mobile money.
        return strtoupper($currency) !== 'NGN';
    }

    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession
    {
        // The amount comes from the order, which computed it from prices held
        // in the database. There is no parameter for a caller to influence it.
        $response = Http::withToken($this->secretKey)
            ->withHeaders(['Idempotency-Key' => $options->idempotencyKey])
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'customer_email' => $order->buyer_email,
                'success_url' => $options->successUrl,
                'cancel_url' => $options->cancelUrl,
                'client_reference_id' => $order->id,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        'unit_amount' => $order->total_amount,
                        'product_data' => ['name' => $order->event->title],
                    ],
                ]],
                'metadata' => ['order_id' => $order->id, 'reference' => $order->reference]
                    + $options->metadata,
            ]);

        $response->throw();

        return new CheckoutSession(
            reference: $response->json('id'),
            redirectUrl: $response->json('url'),
            expiresAt: $response->json('expires_at')
                ? (new \DateTimeImmutable)->setTimestamp($response->json('expires_at'))
                : null,
        );
    }

    /**
     * Stripe signs with HMAC-SHA256 over "timestamp.payload".
     *
     * The timestamp is part of the signed material specifically so a captured
     * request cannot be replayed later, so it is checked as well as the digest.
     */
    public function verifySignature(string $payload, array $headers): bool
    {
        $header = $this->header($headers, 'stripe-signature');

        if ($header === null) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            Log::warning('Stripe webhook rejected: timestamp outside tolerance.');

            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        foreach ($signatures as $signature) {
            // Constant-time: a fast string compare leaks how much of the digest
            // matched, one byte at a time.
            if (hash_equals($expected, (string) $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        if (! $this->verifySignature($payload, $headers)) {
            return null;
        }

        $event = json_decode($payload, true);
        $object = $event['data']['object'] ?? [];

        $type = match ($event['type'] ?? '') {
            'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid'
                ? PaymentEvent::PAID
                : null,
            'checkout.session.expired' => PaymentEvent::EXPIRED,
            'charge.refunded' => PaymentEvent::REFUNDED,
            'charge.dispute.created' => PaymentEvent::DISPUTED,
            'payment_intent.payment_failed' => PaymentEvent::FAILED,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        return new PaymentEvent(
            type: $type,
            reference: $object['id'] ?? '',
            amountMinorUnits: (int) ($object['amount_total'] ?? $object['amount'] ?? 0),
            currency: strtoupper($object['currency'] ?? ''),
            eventId: $event['id'] ?? '',
            raw: $event,
        );
    }

    public function refund(Order $order, int $amountMinorUnits, ?string $reason = null): RefundResult
    {
        $response = Http::withToken($this->secretKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/refunds', array_filter([
                'payment_intent' => $order->gateway_reference,
                'amount' => $amountMinorUnits,
                'reason' => $reason,
            ]));

        if ($response->failed()) {
            return RefundResult::failed($response->json('error.message', 'Refund refused by Stripe.'));
        }

        return new RefundResult(
            succeeded: true,
            reference: $response->json('id'),
            amountMinorUnits: (int) $response->json('amount'),
            currency: strtoupper($response->json('currency')),
        );
    }

    /** Header casing varies by server, so lookup is case-insensitive. */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }
}
