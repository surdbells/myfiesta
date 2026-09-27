<?php

namespace App\Console\Commands;

use App\Services\Disputes\DisputeDesk;
use Illuminate\Console\Command;

/**
 * Remind Admin and Finance of unanswered disputes with five days left, and
 * again with two — each once (DisputeDesk::remindDue).
 */
class RemindDisputeDeadlines extends Command
{
    protected $signature = 'disputes:remind';

    protected $description = 'Email Admin and Finance about unanswered disputes due within five days, and again within two';

    public function handle(DisputeDesk $desk): int
    {
        $sent = $desk->remindDue();

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
