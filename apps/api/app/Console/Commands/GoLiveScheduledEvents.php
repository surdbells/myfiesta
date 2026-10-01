<?php

namespace App\Console\Commands;

use App\Services\Events\ScheduledGoLive;
use Illuminate\Console\Command;

/**
 * Put on sale the nights whose time has come.
 *
 * Every minute, because "on sale at 10am" means 10am to the people refreshing
 * the page for it. Each night is sent as the member who set its time and asked
 * again at that moment — see ScheduledGoLive for what is checked and why.
 */
class GoLiveScheduledEvents extends Command
{
    protected $signature = 'events:go-live';

    protected $description = 'Send the events whose on-sale time has come, as the member who set it';

    public function handle(ScheduledGoLive $goLive): int
    {
        $counts = $goLive->run();

        $sent = $counts[ScheduledGoLive::ON_SALE] + $counts[ScheduledGoLive::IN_REVIEW] + $counts[ScheduledGoLive::NOT_SENT];

        $this->info($sent === 0
            ? 'Nothing due.'
            : sprintf(
                '%d on sale, %d sent for review, %d could not be sent.',
                $counts[ScheduledGoLive::ON_SALE],
                $counts[ScheduledGoLive::IN_REVIEW],
                $counts[ScheduledGoLive::NOT_SENT],
            ));

        return self::SUCCESS;
    }
}
