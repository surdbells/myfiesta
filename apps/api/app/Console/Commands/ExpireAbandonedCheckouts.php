<?php

namespace App\Console\Commands;

use App\Services\Checkout\AbandonedCheckouts;
use Illuminate\Console\Command;

class ExpireAbandonedCheckouts extends Command
{
    protected $signature = 'checkouts:expire';

    protected $description = 'Close pending orders whose buyers never finished paying';

    public function handle(AbandonedCheckouts $checkouts): int
    {
        $closed = $checkouts->expire();

        $this->info($closed === 0 ? 'Nothing abandoned.' : "Closed {$closed} abandoned checkout(s).");

        return self::SUCCESS;
    }
}
