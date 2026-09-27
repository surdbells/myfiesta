<?php

namespace App\Console\Commands;

use App\Services\Refunds\RefundService;
use Illuminate\Console\Command;

/**
 * Ask the processors about refunds that never got an answer.
 *
 * A refund whose request timed out is left waiting rather than called failed,
 * because the money may already have gone back. This is what stops it waiting
 * for ever: each one is looked up at the processor, settled by what it says,
 * and only sent again if the processor has no record of it (RefundService).
 */
class FollowUpRefunds extends Command
{
    protected $signature = 'refunds:follow-up';

    protected $description = 'Find out what happened to refunds the payment processor never answered';

    public function handle(RefundService $refunds): int
    {
        $looked = $refunds->followUpWaiting();

        $this->info($looked === 0 ? 'No refund is waiting for an answer.' : "Asked about {$looked} refund(s).");

        return self::SUCCESS;
    }
}
