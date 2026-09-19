<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telling an organizer's other systems what just happened.
 *
 * Three rules shape all of it.
 *
 * **A webhook never breaks the thing it is about.** An order is paid whether or
 * not somebody's receiver is up. Emitting only writes a row and queues a job,
 * the job catches everything, and a delivery is dispatched after the
 * transaction commits — so a rolled-back order never announces itself, and a
 * receiver that is down never rolls one back.
 *
 * **Retries come from the schedule, not from the queue.** The first attempt is
 * queued the moment the thing happens; if it fails, the delivery records when
 * the next one is due and `webhooks:retry` picks it up. A delivery therefore
 * survives a flushed queue, a restarted worker, and a deploy, and anybody can
 * see in the table exactly what is outstanding.
 *
 * **Every delivery is signed.** The receiver checks the signature with the
 * secret it was shown once, over the timestamp and the exact body, so a
 * forged delivery fails and an old one replayed later fails too.
 */
class Webhooks
{
    /** Seconds to wait before each retry. Most of a day, in total. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 43200];

    public const USER_AGENT = 'myFiesta-Webhooks/1';

    public function __construct(private readonly WebhookTarget $target) {}

    /**
     * Something happened; tell whoever asked to be told.
     *
     * @param  array<string, mixed>  $data
     */
    public function emit(string $organizationId, string $event, array $data): void
    {
        try {
            $endpoints = WebhookEndpoint::query()
                ->where('organization_id', $organizationId)
                ->whereNull('disabled_at')
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->wants($event));

            foreach ($endpoints as $endpoint) {
                $this->queue($endpoint, $event, $data);
            }
        } catch (Throwable $e) {
            // Not the organizer's order failing. Reported, and the sale goes
            // through regardless: a missing announcement is recoverable, a
            // checkout that fell over because of one is not.
            report($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function queue(WebhookEndpoint $endpoint, string $event, array $data): WebhookDelivery
    {
        $id = (string) Str::uuid();

        $delivery = WebhookDelivery::create([
            'id' => $id,
            'webhook_endpoint_id' => $endpoint->id,
            'organization_id' => $endpoint->organization_id,
            'event' => $event,
            'payload' => [
                // The same on every retry, so a receiver that got the first
                // attempt can recognise the second and not act twice.
                'id' => $id,
                'event' => $event,
                'created_at' => now()->toIso8601String(),
                'data' => $data,
            ],
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);

        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    /**
     * One attempt. Never throws.
     *
     * Records what happened and, when it did not work, when to try again —
     * or, once the attempts are spent, that it failed, counting it against
     * the endpoint.
     */
    public function attempt(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;

        if ($delivery->status !== 'pending') {
            return;
        }

        if ($endpoint === null || ! $endpoint->isActive()) {
            $delivery->update([
                'status' => 'failed',
                'next_attempt_at' => null,
                'response_excerpt' => 'The endpoint was turned off before this could be sent.',
            ]);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();

        try {
            // Checked on every attempt, not only when the endpoint was saved:
            // what a name resolves to can change between the two.
            $pin = $this->target->check($endpoint->url);

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'X-MyFiesta-Event' => $delivery->event,
                'X-MyFiesta-Delivery' => $delivery->id,
                'X-MyFiesta-Signature' => self::signature($endpoint->secret, $timestamp, $body),
            ])
                ->withBody($body, 'application/json')
                ->timeout(10)
                ->connectTimeout(5)
                ->withOptions([
                    // Connect to the address that was checked, not to whatever
                    // the name answers with a second time.
                    'curl' => [CURLOPT_RESOLVE => ["{$pin['host']}:{$pin['port']}:{$pin['ip']}"]],
                    // A redirect is a second URL nobody checked.
                    'allow_redirects' => false,
                ])
                ->post($endpoint->url);

            $ok = $response->successful();
            $status = $response->status();
            $excerpt = mb_substr($response->body(), 0, 500);
        } catch (UnsafeWebhookTarget $e) {
            // Not worth retrying: the address will be just as unsafe in an
            // hour. Spent immediately, with the reason recorded.
            $this->settle($delivery, $endpoint, ok: false, status: null, excerpt: $e->getMessage(), final: true);

            return;
        } catch (Throwable $e) {
            $ok = false;
            $status = null;
            $excerpt = mb_substr($e->getMessage(), 0, 500);
        }

        $this->settle($delivery, $endpoint, $ok, $status, $excerpt);
    }

    /**
     * The signature a receiver checks.
     *
     * Over the timestamp and the exact body, so both a forged delivery and an
     * old one replayed later fail — a receiver should refuse a timestamp more
     * than a few minutes old.
     */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function settle(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        bool $ok,
        ?int $status,
        ?string $excerpt,
        bool $final = false,
    ): void {
        $attempts = $delivery->attempts + 1;

        if ($ok) {
            $delivery->update([
                'status' => 'succeeded',
                'attempts' => $attempts,
                'response_status' => $status,
                'response_excerpt' => $excerpt,
                'delivered_at' => now(),
                'next_attempt_at' => null,
            ]);

            $endpoint->update(['consecutive_failures' => 0]);

            return;
        }

        $spent = $final || $attempts > count(self::BACKOFF);

        $delivery->update([
            'status' => $spent ? 'failed' : 'pending',
            'attempts' => $attempts,
            'response_status' => $status,
            'response_excerpt' => $excerpt,
            'next_attempt_at' => $spent ? null : now()->addSeconds(self::BACKOFF[$attempts - 1]),
        ]);

        if (! $spent) {
            return;
        }

        $failures = $endpoint->consecutive_failures + 1;

        $endpoint->update([
            'consecutive_failures' => $failures,
            // A receiver silent for this long is one we stop sending personal
            // data at, and say so on the screen, rather than one we retry for
            // ever.
            ...($failures >= WebhookEndpoint::DISABLE_AFTER ? [
                'disabled_at' => now(),
                'disabled_reason' => "Turned off after {$failures} deliveries in a row failed.",
            ] : []),
        ]);
    }
}
