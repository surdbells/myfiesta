<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Integrations\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt at one delivery.
 *
 * Deliberately a single attempt that never throws: retries are written onto
 * the delivery and picked up by `webhooks:retry`, so the queue's own retry
 * machinery — which would re-run a job that failed halfway, and which a sync
 * queue runs inline inside whatever dispatched it — is never involved.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(Webhooks $webhooks): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);

        if ($delivery !== null) {
            $webhooks->attempt($delivery);
        }
    }
}
