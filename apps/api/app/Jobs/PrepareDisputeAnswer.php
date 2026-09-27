<?php

namespace App\Jobs;

use App\Models\Dispute;
use App\Services\Disputes\DisputeDesk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Put the answer to a new dispute together, off the notice that opened it.
 *
 * The notice is answered at once; asking the processor about the dispute and
 * reading the records can take a few seconds, and a processor waiting on its
 * own webhook would send it again. One attempt that never throws, like
 * DeliverWebhook: every step inside it reports its own failure and lets the
 * rest go on (DisputeDesk::prepare), and staff can do any of it again from the
 * dispute's page.
 */
class PrepareDisputeAnswer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $disputeId) {}

    public function handle(DisputeDesk $desk): void
    {
        $dispute = Dispute::query()->find($this->disputeId);

        if ($dispute !== null) {
            rescue(fn () => $desk->prepare($dispute));
        }
    }
}
