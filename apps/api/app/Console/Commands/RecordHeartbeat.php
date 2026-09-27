<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use App\Support\Operations\Heartbeat;
use Illuminate\Console\Command;

class RecordHeartbeat extends Command
{
    protected $signature = 'app:heartbeat';

    protected $description = 'Record that the scheduler ran, and send the queue a job that records a worker ran it';

    /**
     * Every minute, from the scheduler. The scheduler's own heartbeat is
     * written here and now; the queue's is written by whichever worker picks
     * up the job, so a stale one means the jobs are not being taken.
     */
    public function handle(): int
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);

        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
