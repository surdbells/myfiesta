<?php

namespace App\Console\Commands;

use App\Services\Surveys\SurveySender;
use Illuminate\Console\Command;

/**
 * Ask the people who came, once each night is over (SurveySender).
 */
class SendDueSurveys extends Command
{
    protected $signature = 'surveys:send-due';

    protected $description = 'Email the survey for each night that is over and past its delay';

    public function handle(SurveySender $sender): int
    {
        $queued = $sender->sendDue();

        $this->info($queued === 0 ? 'No survey is due.' : "Queued {$queued} survey email(s).");

        return self::SUCCESS;
    }
}
