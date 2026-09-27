<?php

namespace App\Console\Commands;

use App\Services\Impersonation\Impersonation;
use Illuminate\Console\Command;

class CloseLapsedImpersonations extends Command
{
    protected $signature = 'impersonation:close-lapsed';

    protected $description = 'Record the end of staff sessions whose hour ran out or whose link was never opened';

    public function handle(Impersonation $impersonation): int
    {
        $closed = $impersonation->closeLapsed();

        $this->info($closed === 0 ? 'Nothing lapsed.' : "Closed {$closed} lapsed staff session(s).");

        return self::SUCCESS;
    }
}
