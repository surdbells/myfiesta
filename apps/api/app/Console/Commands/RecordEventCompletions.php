<?php

namespace App\Console\Commands;

use App\Services\Disputes\EventCompletions;
use Illuminate\Console\Command;

/**
 * Write down, once, each night that is over: that it took place, and how the
 * door went (EventCompletions).
 */
class RecordEventCompletions extends Command
{
    protected $signature = 'disputes:record-completions';

    protected $description = 'Record that each finished event took place, from its door, where nobody can edit it';

    public function handle(EventCompletions $completions): int
    {
        $recorded = $completions->recordDue();

        $this->info($recorded === 0 ? 'No event is waiting to be recorded.' : "Recorded {$recorded} event(s).");

        return self::SUCCESS;
    }
}
