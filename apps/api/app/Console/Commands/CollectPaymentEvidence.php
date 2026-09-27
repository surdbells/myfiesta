<?php

namespace App\Console\Commands;

use App\Services\Disputes\ProcessorEvidence;
use Illuminate\Console\Command;

/**
 * Ask the payment processors for their record of payments that have landed.
 *
 * Kept out of the payment notice on purpose: the notice issues the tickets,
 * and a processor slow to answer a second question must not hold them up.
 * Each payment is asked about on the next run after it lands, and again on a
 * widening gap when the processor cannot answer (ProcessorEvidence).
 */
class CollectPaymentEvidence extends Command
{
    protected $signature = 'disputes:collect-evidence';

    protected $description = "Keep the payment processor's own record of each payment, for answering a dispute";

    public function handle(ProcessorEvidence $evidence): int
    {
        $asked = $evidence->collectDue();

        $this->info($asked === 0 ? 'No payment is waiting for its record.' : "Asked about {$asked} payment(s).");

        return self::SUCCESS;
    }
}
