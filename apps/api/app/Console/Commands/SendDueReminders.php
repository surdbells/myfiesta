<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;

/**
 * The thing the scheduler runs.
 *
 * Kept to almost nothing so the logic stays testable without a console.
 * Reminders are found and sent by the dispatcher; this exists to give the
 * scheduler something to call and an operator something to run by hand.
 */
class SendDueReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send event reminders that are due';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDue();

        $this->info($count === 0 ? 'Nothing due.' : "Sent {$count} reminder(s).");

        return self::SUCCESS;
    }
}
