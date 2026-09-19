<?php

namespace App\Console\Commands;

use App\Services\PersonalData\Requests;
use Illuminate\Console\Command;

class PruneDataRequests extends Command
{
    protected $signature = 'privacy:prune';

    protected $description = 'Delete expired data exports and close requests nobody proved';

    public function handle(Requests $requests): int
    {
        ['exports_removed' => $exports, 'unproved_closed' => $closed] = $requests->prune();

        $this->info("Removed {$exports} expired export(s); closed {$closed} unproved request(s).");

        return self::SUCCESS;
    }
}
