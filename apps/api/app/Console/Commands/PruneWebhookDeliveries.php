<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

class PruneWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:prune';

    protected $description = 'Remove webhook deliveries older than the retention window';

    /**
     * A delivery's payload carries a buyer's name and address. The log is for
     * debugging an integration this month, not a second copy of everybody's
     * details kept for ever.
     */
    public function handle(): int
    {
        $removed = WebhookDelivery::query()
            ->where('created_at', '<', now()->subDays(WebhookDelivery::KEEP_DAYS))
            ->delete();

        $this->info("Removed {$removed} old delivery record(s).");

        return self::SUCCESS;
    }
}
