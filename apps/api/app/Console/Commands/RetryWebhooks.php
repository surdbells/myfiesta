<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

class RetryWebhooks extends Command
{
    protected $signature = 'webhooks:retry';

    protected $description = 'Send the webhook deliveries whose next attempt is due';

    /**
     * Bounded, because a receiver that was down for an hour comes back to an
     * hour of deliveries — sent a batch a minute rather than all at once, so
     * it is not knocked straight back over.
     */
    private const BATCH = 200;

    public function handle(): int
    {
        $due = WebhookDelivery::query()
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($due as $id) {
            DeliverWebhook::dispatch($id);
        }

        $this->info($due->isEmpty() ? 'Nothing due.' : "Queued {$due->count()} delivery attempt(s).");

        return self::SUCCESS;
    }
}
