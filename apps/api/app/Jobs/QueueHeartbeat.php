<?php

namespace App\Jobs;

use App\Support\Operations\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Proof that a worker is taking jobs off the queue.
 *
 * Sent every minute by app:heartbeat, on the queue the emails go on, so it
 * waits in the same line they do. When it runs, a worker is alive and that
 * line is moving; when the time it writes goes stale, one of the two is not.
 *
 * One attempt, and nothing to fail: a heartbeat that retried would say a
 * worker was fine when it had been failing for three tries.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE);
    }
}
