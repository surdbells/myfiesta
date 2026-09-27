<?php

namespace App\Services\Payments;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\FindsCheckouts;
use App\Contracts\Payments\FindsRefunds;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\RefundNotice;
use App\Contracts\Payments\RefundResult;
use App\Contracts\Payments\TotalsRefunds;
use App\Models\Order;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe, for every currency except naira.
 *
 * Talks to Stripe's REST API directly rather than through Cashier. Cashier is
 * built around subscriptions and a billable model; a ticket sale is a one-shot
 * charge against an order that may have no account behind it at all, since
 * guest checkout is the primary path. Using it here would mean adopting its
 * shape without its benefits.
 */
class StripeGateway implements FindsCheckouts, FindsRefunds, PaymentGateway, TotalsRefunds
{
    /**
     * How long Stripe's payment page stays open.
     *
     * Thirty minutes, Stripe's minimum, instead of its default day: a page
     * left open overnight could otherwise take money for tickets released
     * hours ago. The stock hold is set from this (CheckoutService::HOLD_MINUTES)
     * so that it always outlives the page.
     */
    public const SESSION_MINUTES = 30;

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
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->getTimestamp(),
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
        /*
         * No secret, no webhook — in every environment.
         *
         * An HMAC keyed with an empty string is a digest anybody can compute,
         * so a blank STRIPE_WEBHOOK_SECRET would turn "signed by Stripe" into
         * "signed by whoever read this file", and any of them could mark an
         * order paid. Refusing everything is the only safe reading of a
         * missing key; the start-up check (Preflight) is what stops that
         * reaching production at all.
         */
        if ($this->webhookSecret === '') {
            Log::warning('Stripe webhook rejected: STRIPE_WEBHOOK_SECRET is not set.');

            return false;
        }

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
        $name = $event['type'] ?? '';

        $type = match ($name) {
            'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid'
                ? PaymentEvent::PAID
                : null,
            'checkout.session.expired' => PaymentEvent::EXPIRED,
            'charge.refunded',
            'refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated' => PaymentEvent::REFUNDED,
            'charge.dispute.created' => PaymentEvent::DISPUTED,
            // 'warning_closed' is a card network dropping an enquiry before it
            // became a dispute, which is the organizer keeping the money.
            'charge.dispute.closed' => match ($object['status'] ?? '') {
                'lost' => PaymentEvent::DISPUTE_LOST,
                'won', 'warning_closed' => PaymentEvent::DISPUTE_WON,
                default => null,
            },
            'payment_intent.payment_failed' => PaymentEvent::FAILED,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $refund = $type === PaymentEvent::REFUNDED ? $this->refundNotice($name, $object) : null;

        if ($type === PaymentEvent::REFUNDED && $refund === null) {
            return null;
        }

        // A dispute's object is the dispute, and a refund's is the refund or
        // the charge; each names the payment it is about, which is what the
        // order kept when it was paid. Everything else is the thing itself.
        $reference = match (true) {
            $type === PaymentEvent::REFUNDED => $object['payment_intent'] ?? '',
            str_starts_with($type, 'dispute') || $type === PaymentEvent::DISPUTED => $object['payment_intent']
                ?? $object['charge']
                ?? $object['id']
                ?? '',
            default => $object['id'] ?? '',
        };

        return new PaymentEvent(
            type: $type,
            reference: (string) $reference,
            amountMinorUnits: (int) ($object['amount_total'] ?? $object['amount_refunded'] ?? $object['amount'] ?? 0),
            currency: strtoupper($object['currency'] ?? ''),
            eventId: $event['id'] ?? '',
            raw: $event,
            paymentReference: $object['payment_intent'] ?? null,
            refund: $refund,
        );
    }

    /**
     * What a refund event says, or null when there is nothing in it for us.
     *
     * charge.refunded is the one that finds refunds made in Stripe's own
     * dashboard. It carries the running total for the payment, and a running
     * total cannot be counted twice: read again, or out of order, it still
     * says the same thing about how much has gone back.
     *
     * The events about a single refund are only listened to when the refund
     * is one of ours, tagged on the way out (refund() below). They settle a
     * refund of ours whose answer never came back, and they are how Stripe
     * says one failed after the fact. One made elsewhere is left to the
     * running total; counting it here as well would count it twice.
     */
    private function refundNotice(string $name, array $object): ?RefundNotice
    {
        if ($name === 'charge.refunded') {
            return new RefundNotice(
                status: RefundNotice::SUCCEEDED,
                totalRefundedMinorUnits: (int) ($object['amount_refunded'] ?? 0),
                // Only on older API versions, which list the refunds on the
                // charge. When they are there, they say which were ours.
                parts: array_values(array_map(
                    fn (array $refund) => $this->oneRefund($refund),
                    array_filter($object['refunds']['data'] ?? [], 'is_array'),
                )),
            );
        }

        $notice = $this->oneRefund($object);

        return $notice->ourReference !== null ? $notice : null;
    }

    private function oneRefund(array $refund): RefundNotice
    {
        return new RefundNotice(
            // Pending is a refund Stripe has accepted and is still paying out,
            // which is a yes. requires_action is Stripe waiting on somebody.
            status: match ($refund['status'] ?? 'succeeded') {
                'failed', 'canceled' => RefundNotice::FAILED,
                'requires_action' => RefundNotice::PENDING,
                default => RefundNotice::SUCCEEDED,
            },
            amountMinorUnits: (int) ($refund['amount'] ?? 0),
            processorReference: $refund['id'] ?? null,
            ourReference: $refund['metadata']['refund_id'] ?? null,
        );
    }

    /**
     * Ask Stripe for money back, once, however many times this is called.
     *
     * The key is our Refund row's id, sent as Stripe's Idempotency-Key: a
     * second request with the same key gets the first one's answer instead of
     * a second refund. That is what makes it safe to try again when a request
     * times out — here, straight away and a couple of times, and later from
     * refunds:follow-up. Without a key there is no retry at all, because a
     * second request could be a second refund.
     *
     * The key goes in the metadata as well. It is how a refund event, or a
     * look through the payment's refunds (findRefund), says which of ours a
     * refund was.
     */
    public function refund(
        Order $order,
        int $amountMinorUnits,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): RefundResult {
        $metadata = array_filter(['refund_id' => $idempotencyKey, 'reason' => $reason], filled(...));

        try {
            $response = Http::withToken($this->secretKey)
                ->withHeaders($idempotencyKey === null ? [] : ['Idempotency-Key' => 'refund-'.$idempotencyKey])
                ->retry(
                    [250, 1000],
                    when: fn (Throwable $e) => $idempotencyKey !== null && $this->worthAnotherTry($e),
                    throw: false,
                )
                ->asForm()
                ->post('https://api.stripe.com/v1/refunds', array_filter([
                    // The payment, not the checkout session the order was made
                    // against: Stripe refuses a session id here.
                    'payment_intent' => $order->gateway_payment_reference ?? $order->gateway_reference,
                    'amount' => $amountMinorUnits,
                    // Not Stripe's `reason`, which takes one of three words —
                    // duplicate, fraudulent, requested_by_customer — and refuses
                    // the whole refund over anything else. Every reason here is a
                    // sentence somebody wrote, and most are not a customer's
                    // request, so none of the three is true of them. Kept as
                    // metadata instead, where Stripe shows it beside the refund
                    // and checks nothing.
                    'metadata' => $metadata ?: null,
                ]));
        } catch (ConnectionException $e) {
            return RefundResult::unknown('Stripe could not be reached: '.$e->getMessage());
        }

        // Stripe's own error, or another request with this key still under
        // way. Either way the refund may exist, and a "no" here would be a
        // guess.
        if ($response->serverError() || $response->status() === 409) {
            return RefundResult::unknown($response->json('error.message') ?? 'Stripe answered with an error ('.$response->status().').');
        }

        if ($response->failed()) {
            return RefundResult::failed($response->json('error.message', 'Refund refused by Stripe.'));
        }

        // Accepted, and already known not to have paid out. Rare, but a
        // refund Stripe calls failed has moved nothing, whatever the status
        // code of the answer.
        if (in_array($response->json('status'), ['failed', 'canceled'], true)) {
            return RefundResult::failed($response->json('failure_reason') ?? 'Stripe could not pay the refund out.');
        }

        return new RefundResult(
            succeeded: true,
            reference: (string) $response->json('id'),
            amountMinorUnits: (int) $response->json('amount'),
            currency: strtoupper((string) $response->json('currency')),
        );
    }

    /**
     * The refund tagged with this key, among the payment's refunds.
     *
     * Asked before a refund that got no answer is sent again. With the key
     * that is belt and braces — Stripe would answer the repeat with the first
     * refund — but Stripe forgets keys after a day, and the tag in the
     * metadata it keeps for good.
     */
    public function findRefund(
        Order $order,
        string $idempotencyKey,
        int $amountMinorUnits,
        DateTimeInterface $since,
        array $alreadyKnown = [],
    ): ?RefundResult {
        foreach ($this->refundsOn($order) as $refund) {
            if (($refund['metadata']['refund_id'] ?? null) !== $idempotencyKey) {
                continue;
            }

            return in_array($refund['status'] ?? '', ['failed', 'canceled'], true)
                ? RefundResult::failed($refund['failure_reason'] ?? 'Stripe could not pay the refund out.')
                : new RefundResult(
                    succeeded: true,
                    reference: (string) $refund['id'],
                    amountMinorUnits: (int) ($refund['amount'] ?? $amountMinorUnits),
                    currency: strtoupper((string) ($refund['currency'] ?? $order->currency)),
                );
        }

        return null;
    }

    /** The checkout session a payment intent was made on. */
    public function checkoutFor(string $paymentReference): ?string
    {
        $response = Http::withToken($this->secretKey)
            ->get('https://api.stripe.com/v1/checkout/sessions', [
                'payment_intent' => $paymentReference,
                'limit' => 1,
            ])
            ->throw();

        $id = $response->json('data.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * What charge.refunded would say now, asked for rather than announced.
     *
     * Counted from the refunds themselves. One Stripe has accepted and is
     * still paying out counts, as it does in the charge's own total; one that
     * failed, was cancelled, or is waiting on somebody has moved nothing.
     */
    public function refundedSoFar(Order $order): RefundNotice
    {
        $parts = array_map(fn (array $refund) => $this->oneRefund($refund), $this->refundsOn($order));

        return new RefundNotice(
            status: RefundNotice::SUCCEEDED,
            totalRefundedMinorUnits: array_sum(array_map(
                fn (RefundNotice $part) => $part->status === RefundNotice::SUCCEEDED ? (int) $part->amountMinorUnits : 0,
                $parts,
            )),
            parts: $parts,
        );
    }

    /**
     * Every refund on the order's payment, ours or not.
     *
     * A hundred is Stripe's most in one page, and more than any ticket order
     * has ever been refunded in.
     *
     * @return list<array<string, mixed>>
     */
    private function refundsOn(Order $order): array
    {
        $response = Http::withToken($this->secretKey)
            ->get('https://api.stripe.com/v1/refunds', [
                'payment_intent' => $order->gateway_payment_reference ?? $order->gateway_reference,
                'limit' => 100,
            ])
            ->throw();

        return array_values(array_filter($response->json('data') ?? [], 'is_array'));
    }

    /**
     * Whether a failed request is worth sending again with the same key.
     *
     * No connection, a timeout, Stripe's own error, or being asked to slow
     * down. A refusal — no balance, an amount too large — would only be
     * refused again.
     */
    private function worthAnotherTry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->serverError() || in_array($e->response->status(), [409, 429], true));
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
