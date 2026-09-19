<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Console\Command;

class SendCampaigns extends Command
{
    protected $signature = 'campaigns:send';

    protected $description = 'Send the campaigns whose time has come';

    public function handle(CampaignSender $sender): int
    {
        $due = Campaign::query()
            ->where(fn ($q) => $q
                ->where(fn ($due) => $due->where('status', 'scheduled')->where('scheduled_for', '<=', now()))
                ->orWhere(fn ($stale) => $stale->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(CampaignSender::STALE_MINUTES))))
            ->orderBy('scheduled_for')
            ->get();

        $written = 0;

        foreach ($due as $campaign) {
            $written += $sender->send($campaign);
        }

        $this->info($due->isEmpty() ? 'Nothing due.' : "Sent {$due->count()} campaign(s) to {$written} people.");

        return self::SUCCESS;
    }
}
