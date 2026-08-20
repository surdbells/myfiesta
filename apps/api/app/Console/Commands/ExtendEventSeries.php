<?php

namespace App\Console\Commands;

use App\Services\Events\SeriesGenerator;
use Illuminate\Console\Command;

/**
 * Push the rolling window forward.
 *
 * A weekly night with no end date is infinite, so occurrences exist a few
 * months out and this keeps them topped up. Daily is often enough: the window
 * is measured in months, and nobody is selling tickets to a night six months
 * away that appeared six hours late.
 */
class ExtendEventSeries extends Command
{
    protected $signature = 'series:extend';

    protected $description = 'Materialise upcoming occurrences of repeating events';

    public function handle(SeriesGenerator $generator): int
    {
        $created = $generator->generateAll();

        $this->info($created === 0 ? 'Nothing to add.' : "Created {$created} occurrence(s).");

        return self::SUCCESS;
    }
}
