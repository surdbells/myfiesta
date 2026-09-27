<?php

namespace App\Services\Legacy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Asking Stripe what happened to a payment, and nothing else.
 *
 * Every request here is a GET. The reconciliation is run against the live
 * account on the day of a cutover, and the one property it must have is that
 * running it cannot move money — so there is no method on this class that
 * could, and the key it is given should be a restricted key that cannot
 * either (docs/CUTOVER.md).
 *
 * Paced, because a cutover is a few thousand orders at three or four requests
 * each and Stripe's limit is shared with the live checkout still taking money
 * on the same account. Retried with backoff on the answers Stripe says are
 * worth retrying — a 429, a 5xx, a dropped connection — and not on anything
 * else, because a request refused for its content will be refused again.
 */
class StripeReader
{
    private const BASE = 'https://api.stripe.com/v1/';

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly string $secretKey,
        private readonly int $perSecond = 20,
        private readonly int $attempts = 5,
    ) {}

    /**
     * The payment behind a Checkout Session or PaymentIntent id.
     *
     * With its disputes as well as its refunds. A chargeback takes the money
     * back without touching either thing "paid" is read from — the session
     * stays paid, the PaymentIntent stays succeeded — and without a Refund
     * object, so a payment asked about only those ways reads as whole when
     * the bank has already taken it.
     *
     * @return array{
     *     reference: string,
     *     payment_intent: ?string,
     *     paid: bool,
     *     status: string,
     *     amount: ?int,
     *     currency: ?string,
     *     refunds: list<array{id: string, amount: int, currency: string, status: string, created: int}>,
     *     disputes: list<array{id: string, amount: int, currency: string, status: string, reason: string, created: int}>
     * }
     *
     * @throws StripeReadFailed
     */
    public function payment(string $reference): array
    {
        if (str_starts_with($reference, 'cs_')) {
            $session = $this->get('checkout/sessions/'.rawurlencode($reference), ['expand' => ['payment_intent']]);

            $intent = $session['payment_intent'] ?? null;

            // Expanded when asked, but an id is still an answer: fetched
            // separately rather than taken as "no payment".
            if (is_string($intent)) {
                $intent = $this->get('payment_intents/'.rawurlencode($intent));
            }

            $intent = is_array($intent) ? $intent : null;

            // A session is paid when Stripe says so of the session, or of the
            // payment behind it. Either is the money having arrived.
            $paid = ($session['payment_status'] ?? null) === 'paid'
                || ($intent['status'] ?? null) === 'succeeded';

            $amount = $session['amount_total'] ?? $intent['amount_received'] ?? $intent['amount'] ?? null;
            $currency = $session['currency'] ?? $intent['currency'] ?? null;
            $status = ($session['status'] ?? 'unknown').'/'.($session['payment_status'] ?? 'unknown');
        } else {
            $intent = $this->get('payment_intents/'.rawurlencode($reference));

            $paid = ($intent['status'] ?? null) === 'succeeded';
            $amount = $paid
                ? ($intent['amount_received'] ?? $intent['amount'] ?? null)
                : ($intent['amount'] ?? null);
            $currency = $intent['currency'] ?? null;
            $status = (string) ($intent['status'] ?? 'unknown');
        }

        $intentId = $intent['id'] ?? null;

        return [
            'reference' => $reference,
            'payment_intent' => $intentId,
            'paid' => $paid,
            'status' => $status,
            'amount' => $amount === null ? null : (int) $amount,
            'currency' => $currency === null ? null : strtoupper((string) $currency),
            'refunds' => $intentId === null ? [] : $this->refunds($intentId),
            'disputes' => $intentId === null ? [] : $this->disputes($intentId),
        ];
    }

    /**
     * Every refund against a payment, however many pages that takes.
     *
     * @return list<array{id: string, amount: int, currency: string, status: string, created: int}>
     */
    public function refunds(string $paymentIntent): array
    {
        return $this->everyPage('refunds', $paymentIntent, fn (array $refund) => [
            'id' => (string) $refund['id'],
            'amount' => (int) $refund['amount'],
            'currency' => strtoupper((string) $refund['currency']),
            'status' => (string) $refund['status'],
            'created' => (int) $refund['created'],
        ]);
    }

    /**
     * Every dispute against a payment: chargebacks, and the inquiries that
     * come before some of them.
     *
     * @return list<array{id: string, amount: int, currency: string, status: string, reason: string, created: int}>
     */
    public function disputes(string $paymentIntent): array
    {
        return $this->everyPage('disputes', $paymentIntent, fn (array $dispute) => [
            'id' => (string) $dispute['id'],
            'amount' => (int) $dispute['amount'],
            'currency' => strtoupper((string) $dispute['currency']),
            'status' => (string) $dispute['status'],
            'reason' => (string) ($dispute['reason'] ?? ''),
            'created' => (int) $dispute['created'],
        ]);
    }

    /**
     * A list Stripe keeps against one payment, however many pages it takes.
     *
     * Stripe lists a hundred at a time. Nobody refunds one payment a hundred
     * times, but a list that stops at the first page is a list that is wrong
     * the one time somebody did.
     *
     * @template T
     *
     * @param  \Closure(array<string, mixed>): T  $shape
     * @return list<T>
     */
    private function everyPage(string $path, string $paymentIntent, \Closure $shape): array
    {
        $items = [];
        $after = null;

        do {
            $page = $this->get($path, array_filter([
                'payment_intent' => $paymentIntent,
                'limit' => 100,
                'starting_after' => $after,
            ]));

            foreach ($page['data'] ?? [] as $item) {
                $items[] = $shape($item);

                $after = (string) $item['id'];
            }
            // An empty page that claims there is more would ask for itself
            // again forever.
        } while (($page['has_more'] ?? false) === true && ($page['data'] ?? []) !== []);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws StripeReadFailed
     */
    private function get(string $path, array $query = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pace();

            try {
                $response = Http::withToken($this->secretKey)
                    ->acceptJson()
                    ->timeout(30)
                    ->get(self::BASE.$path, $query);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->attempts) {
                    throw StripeReadFailed::unavailable('Stripe could not be reached: '.$e->getMessage());
                }

                $this->backOff($attempt, null);

                continue;
            }

            if ($response->successful()) {
                return (array) $response->json();
            }

            $this->refuseOrRetry($response, $attempt);

            $this->backOff($attempt, $response->header('Retry-After'));
        }
    }

    /**
     * Throws unless the response is worth asking again.
     *
     * @throws StripeReadFailed
     */
    private function refuseOrRetry(Response $response, int $attempt): void
    {
        $status = $response->status();
        $message = (string) $response->json('error.message', 'no message');

        // The key belongs to the other mode — a test key asking about a live
        // session. Stripe calls it missing, and so would this, for every order
        // in the import. Stopped here instead.
        if ($status === 404 && str_contains($message, 'similar object exists in')) {
            throw StripeReadFailed::refused(
                'The Stripe key is for the wrong mode: '.$message
            );
        }

        if ($status === 404) {
            throw StripeReadFailed::missing($message);
        }

        if ($status === 401 || $status === 403) {
            throw StripeReadFailed::refused(
                "Stripe refused the key ({$status}): {$message}. It needs read access to "
                .'Checkout Sessions, PaymentIntents, Refunds and Disputes.'
            );
        }

        $retryable = $status === 429
            || $response->serverError()
            || $response->header('Stripe-Should-Retry') === 'true';

        if (! $retryable || $attempt >= $this->attempts) {
            throw StripeReadFailed::unavailable("Stripe answered {$status}: {$message}");
        }
    }

    /**
     * No faster than the rate asked for, across every request this makes.
     */
    private function pace(): void
    {
        if ($this->perSecond <= 0) {
            return;
        }

        $wait = $this->lastRequestAt + (1 / $this->perSecond) - microtime(true);

        if ($wait > 0) {
            Sleep::usleep((int) ($wait * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }

    /**
     * One second, then two, four, eight — or whatever Stripe asked for.
     */
    private function backOff(int $attempt, ?string $retryAfter): void
    {
        $seconds = is_numeric($retryAfter)
            ? (float) $retryAfter
            : min(30, 2 ** ($attempt - 1));

        Sleep::for((int) round($seconds * 1000))->milliseconds();
    }
}
