<?php

namespace App\Services\Payments;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\RefundResult;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

/**
 * Paystack, for naira.
 *
 * Card penetration in Nigeria is far lower than in Canada, so the channels
 * below matter as much as the integration: bank transfer and USSD carry a large
 * share of online payments there. Launching NGN with cards only would work
 * technically and underperform commercially.
 *
 * Amounts are in kobo, which happens to match the minor-unit convention used
 * throughout this codebase — no conversion, and none should be introduced.
 */
class PaystackGateway implements PaymentGateway
{
    private const BASE = 'https://api.paystack.co';

    public function __construct(private readonly string $secretKey) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function supports(string $currency): bool
    {
        return strtoupper($currency) === 'NGN';
    }

    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession
    {
        $response = Http::withToken($this->secretKey)
            ->post(self::BASE.'/transaction/initialize', [
                'email' => $order->buyer_email,
                // From the order, never from a caller.
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                // Paystack's own idempotency handle. Re-initialising with the
                // same reference returns the existing transaction rather than
                // opening a second one.
                'reference' => $order->reference.'-'.substr($options->idempotencyKey, 0, 8),
                'callback_url' => $options->successUrl,
                'channels' => ['card', 'bank', 'bank_transfer', 'ussd', 'mobile_money', 'qr'],
                'metadata' => ['order_id' => $order->id, 'reference' => $order->reference]
                    + $options->metadata,
            ]);

        $response->throw();

        return new CheckoutSession(
            reference: $response->json('data.reference'),
            redirectUrl: $response->json('data.authorization_url'),
        );
    }

    /**
     * Paystack signs the raw body with HMAC-SHA512 using the secret key.
     *
     * Different algorithm from Stripe, same obligation: an unverified payload
     * is not evidence of anything.
     */
    public function verifySignature(string $payload, array $headers): bool
    {
        $signature = $this->header($headers, 'x-paystack-signature');

        if ($signature === null) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha512', $payload, $this->secretKey),
            $signature,
        );
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        if (! $this->verifySignature($payload, $headers)) {
            return null;
        }

        $event = json_decode($payload, true);
        $data = $event['data'] ?? [];

        $type = match ($event['event'] ?? '') {
            'charge.success' => PaymentEvent::PAID,
            'charge.failed' => PaymentEvent::FAILED,
            'refund.processed' => PaymentEvent::REFUNDED,
            'charge.dispute.create' => PaymentEvent::DISPUTED,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        return new PaymentEvent(
            type: $type,
            reference: $data['reference'] ?? '',
            amountMinorUnits: (int) ($data['amount'] ?? 0),
            currency: strtoupper($data['currency'] ?? 'NGN'),
            // Paystack does not send a distinct event id, so the transaction id
            // stands in for de-duplicating retried deliveries.
            eventId: (string) ($data['id'] ?? $data['reference'] ?? ''),
            raw: $event,
        );
    }

    public function refund(Order $order, int $amountMinorUnits, ?string $reason = null): RefundResult
    {
        $response = Http::withToken($this->secretKey)
            ->post(self::BASE.'/refund', array_filter([
                'transaction' => $order->gateway_reference,
                'amount' => $amountMinorUnits,
                'merchant_note' => $reason,
            ]));

        if ($response->failed() || $response->json('status') !== true) {
            return RefundResult::failed(
                $response->json('message', 'Refund refused by Paystack.')
            );
        }

        return new RefundResult(
            succeeded: true,
            reference: (string) $response->json('data.id'),
            amountMinorUnits: (int) $response->json('data.amount', $amountMinorUnits),
            currency: strtoupper($response->json('data.currency', $order->currency)),
        );
    }

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
