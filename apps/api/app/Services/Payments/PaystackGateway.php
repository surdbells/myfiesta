<?php

namespace App\Services\Payments;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\FindsRefunds;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\RefundNotice;
use App\Contracts\Payments\RefundResult;
use App\Models\Order;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
class PaystackGateway implements FindsRefunds, PaymentGateway
{
    private const BASE = 'https://api.paystack.co';

    /** How our key is written into a refund's merchant note: "[myFiesta refund <key>]". */
    private const TAG = '[myFiesta refund ';

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
        // The same rule as Stripe's: a digest keyed with nothing is one
        // anybody can make, so with no key there is no webhook at all.
        if ($this->secretKey === '') {
            Log::warning('Paystack webhook rejected: PAYSTACK_SECRET_KEY is not set.');

            return false;
        }

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
        $name = (string) ($event['event'] ?? '');
        $data = $event['data'] ?? [];
        // A dispute carries the transaction it is about as an object of its
        // own; a refund names it by reference; a charge is the transaction.
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];

        $type = match ($name) {
            'charge.success' => PaymentEvent::PAID,
            'charge.failed' => PaymentEvent::FAILED,
            'refund.processed', 'refund.failed' => PaymentEvent::REFUNDED,
            'charge.dispute.create' => PaymentEvent::DISPUTED,
            // Paystack resolves a dispute one way or the other; accepting the
            // merchant's side is the organizer keeping the money.
            'charge.dispute.resolve' => ($data['resolution'] ?? '') === 'merchant-accepted'
                ? PaymentEvent::DISPUTE_LOST
                : PaymentEvent::DISPUTE_WON,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        // What the order was paid under. A charge is the transaction itself;
        // a dispute and a refund each point at it, and neither has a
        // `reference` of its own — reading that one found no order for any
        // of them.
        $reference = $type === PaymentEvent::PAID || $type === PaymentEvent::FAILED
            ? ($data['reference'] ?? '')
            : ($data['transaction_reference'] ?? $transaction['reference'] ?? $data['reference'] ?? '');

        return new PaymentEvent(
            type: $type,
            reference: (string) $reference,
            amountMinorUnits: (int) ($data['amount'] ?? $transaction['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? $transaction['currency'] ?? 'NGN')),
            eventId: $this->eventId($name, $data),
            raw: $event,
            // The transaction's own id, kept on the order when it is paid: it
            // is what Paystack's refund list names a transaction by.
            paymentReference: $type === PaymentEvent::PAID && isset($data['id']) ? (string) $data['id'] : null,
            refund: $type === PaymentEvent::REFUNDED ? $this->refundNotice($name, $data) : null,
        );
    }

    /**
     * What stands in for an event id, which Paystack does not send.
     *
     * The event's name and the id of the thing it is about. The id alone was
     * not enough: a dispute is opened and resolved under the same dispute id,
     * so the resolution arrived looking like a repeat of the opening and was
     * dropped — a lost chargeback never took anything back. A refund notice
     * has no id at all in some shapes, so its references and amount stand in.
     */
    private function eventId(string $name, array $data): string
    {
        $identity = $data['id']
            ?? $data['refund_reference']
            ?? (isset($data['transaction_reference'])
                ? $data['transaction_reference'].':'.($data['amount'] ?? '')
                : null)
            ?? $data['reference']
            ?? null;

        return $identity === null ? '' : $name.':'.$identity;
    }

    /**
     * One refund, as Paystack tells it.
     *
     * Paystack sends a notice for every refund on the account, including the
     * ones made from its dashboard, so an unmatched one is a refund made
     * elsewhere. It gives no running total, which is why these are matched to
     * ours one at a time (ProcessorRefunds).
     *
     * The notice names the refund by refund_reference, not by the id our own
     * request was answered with, so it will not find a refund of ours by that
     * (ProcessorRefunds falls back to the amount). It is kept all the same:
     * it is what the dashboard shows, and it is what finds the row for one
     * made elsewhere when the same notice is heard a second time.
     */
    private function refundNotice(string $name, array $data): RefundNotice
    {
        $reference = $data['id'] ?? $data['refund_reference'] ?? null;

        return new RefundNotice(
            status: $name === 'refund.failed' ? RefundNotice::FAILED : RefundNotice::SUCCEEDED,
            amountMinorUnits: (int) ($data['amount'] ?? 0),
            processorReference: $reference === null || $reference === '' ? null : (string) $reference,
            ourReference: $this->ourKeyIn((string) ($data['merchant_note'] ?? '')),
        );
    }

    /**
     * Paystack takes no idempotency key, so nothing here sends a refund twice.
     *
     * No retry on a timeout: a second request would be a second refund, and
     * Paystack has no way of telling it apart. What a timeout gets instead is
     * RefundResult::unknown, and the follow-up asks Paystack whether the
     * refund is there (findRefund) before anything is sent again.
     *
     * Our key goes in the merchant note, where the dashboard shows it and a
     * look through the refunds can find it.
     */
    public function refund(
        Order $order,
        int $amountMinorUnits,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): RefundResult {
        $note = trim(($reason ?? '').($idempotencyKey === null ? '' : ' '.self::TAG.$idempotencyKey.']'));

        try {
            $response = Http::withToken($this->secretKey)
                ->post(self::BASE.'/refund', array_filter([
                    'transaction' => $order->gateway_reference,
                    'amount' => $amountMinorUnits,
                    'merchant_note' => $note === '' ? null : $note,
                ]));
        } catch (ConnectionException $e) {
            return RefundResult::unknown('Paystack could not be reached: '.$e->getMessage());
        }

        if ($response->serverError()) {
            return RefundResult::unknown($response->json('message') ?? 'Paystack answered with an error ('.$response->status().').');
        }

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

    /**
     * The refund made for one of ours, among the transaction's refunds.
     *
     * Our key in the merchant note says so for certain. A refund with no note
     * of ours can still be it — made before notes carried the key — so one
     * for the same amount, made since ours was sent and not already on record,
     * is taken as it. A failed refund is only ever matched by its note: an
     * unrelated failure for the same amount says nothing about ours.
     */
    public function findRefund(
        Order $order,
        string $idempotencyKey,
        int $amountMinorUnits,
        DateTimeInterface $since,
        array $alreadyKnown = [],
    ): ?RefundResult {
        $response = Http::withToken($this->secretKey)
            ->get(self::BASE.'/refund', array_filter([
                'transaction' => $order->gateway_payment_reference ?? $order->gateway_reference,
                'currency' => $order->currency,
                'from' => CarbonImmutable::instance($since)->subDay()->toIso8601String(),
                'perPage' => 100,
            ]))
            ->throw();

        if ($response->json('status') !== true) {
            throw new RuntimeException('Paystack would not list refunds: '.$response->json('message', 'no reason given'));
        }

        $theirs = collect($response->json('data') ?? [])
            ->filter(fn ($refund) => is_array($refund) && $this->isFor($order, $refund));

        $tagged = $theirs->first(fn (array $refund) => $this->ourKeyIn((string) ($refund['merchant_note'] ?? '')) === $idempotencyKey);

        $match = $tagged ?? $theirs
            ->filter(fn (array $refund) => (int) ($refund['amount'] ?? 0) === $amountMinorUnits
                && ($refund['status'] ?? '') !== 'failed'
                && ! in_array((string) ($refund['id'] ?? ''), $alreadyKnown, true)
                && CarbonImmutable::parse($refund['createdAt'] ?? $refund['created_at'] ?? 'now')
                    ->greaterThanOrEqualTo(CarbonImmutable::instance($since)->subMinutes(5)))
            ->sortBy(fn (array $refund) => $refund['createdAt'] ?? $refund['created_at'] ?? '')
            ->first();

        if ($match === null) {
            return null;
        }

        if (($match['status'] ?? '') === 'failed') {
            return RefundResult::failed('Paystack could not pay the refund out.');
        }

        return new RefundResult(
            succeeded: true,
            reference: (string) ($match['id'] ?? ''),
            amountMinorUnits: (int) ($match['amount'] ?? $amountMinorUnits),
            currency: strtoupper((string) ($match['currency'] ?? $order->currency)),
        );
    }

    /**
     * Whether a listed refund is against this order's transaction.
     *
     * Checked here as well as asked for in the query, because a list that
     * ignored the filter would otherwise hand back somebody else's refunds.
     */
    private function isFor(Order $order, array $refund): bool
    {
        $transaction = $refund['transaction'] ?? null;

        $candidates = is_array($transaction)
            ? [$transaction['reference'] ?? null, isset($transaction['id']) ? (string) $transaction['id'] : null]
            : [$transaction === null ? null : (string) $transaction, $refund['transaction_reference'] ?? null];

        return array_intersect(
            array_filter($candidates, fn ($candidate) => $candidate !== null && $candidate !== ''),
            array_filter([$order->gateway_reference, $order->gateway_payment_reference]),
        ) !== [];
    }

    /** Our key, if a merchant note carries one. */
    private function ourKeyIn(string $note): ?string
    {
        $at = strrpos($note, self::TAG);

        if ($at === false) {
            return null;
        }

        $key = substr($note, $at + strlen(self::TAG));
        $key = rtrim($key, ']');

        return $key === '' ? null : $key;
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
