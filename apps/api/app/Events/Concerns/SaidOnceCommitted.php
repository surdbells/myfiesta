<?php

namespace App\Events\Concerns;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Said once what happened has committed, and never answered.
 *
 * These are said in the middle of work that has already counted: an order
 * paid, money gone back, a guest through the door. Laravel runs whatever waits
 * on a commit in a plain loop, in the order it was asked for, so a listener
 * that threw there would skip everything queued after it — the buyer's tickets
 * email, a resale seller's payout, the refund's audit entry — and the caller
 * would answer with an error for something that had happened. The processor
 * retries, finds the order already paid, and nothing is ever sent again.
 *
 * So a failure here is reported and goes no further. That is also why every
 * listener on these must be queued (ShouldQueue, checked in DomainEventsTest):
 * one that fails then fails alone, and is tried again, instead of being lost
 * along with the listeners after it.
 *
 * In place of Dispatchable's dispatch(), so the ordinary way to say one is the
 * safe way. A rollback still means it is never said.
 *
 * @see ShouldQueue
 */
trait SaidOnceCommitted
{
    public static function dispatch(mixed ...$arguments): void
    {
        DB::afterCommit(fn () => rescue(fn () => event(new static(...$arguments)), report: true));
    }
}
