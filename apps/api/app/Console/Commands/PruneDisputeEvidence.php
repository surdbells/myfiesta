<?php

namespace App\Console\Commands;

use App\Services\Disputes\EvidenceRetention;
use Illuminate\Console\Command;

/**
 * Let go of what was kept to answer a dispute, 18 months after the night
 * (EvidenceRetention). Never an order, a ticket, a scan, the ledger or the
 * audit trail.
 */
class PruneDisputeEvidence extends Command
{
    protected $signature = 'disputes:prune-evidence';

    protected $description = 'Clear purchase addresses and delete ticket history, payment records and the answers to closed disputes past the dispute window';

    public function handle(EvidenceRetention $retention): int
    {
        $done = $retention->prune();

        $this->info("Cleared the address on {$done['orders']} order(s); deleted {$done['activity']} ticket history row(s), {$done['payments']} payment record(s) and {$done['answers']} dispute answer(s).");

        return self::SUCCESS;
    }
}
