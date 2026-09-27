<?php

namespace App\Console\Commands;

use App\Support\Preflight as Checks;
use Illuminate\Console\Command;

/**
 * Would production start with this configuration?
 *
 * Run by the API image before php-fpm starts and by api-migrate before it
 * migrates, so a deployment missing a secret stops at the door with every
 * problem listed, rather than serving checkouts that can never be paid. The
 * worker and the scheduler check the same list as they start.
 *
 * Outside production it reports the same list and fails only with --strict,
 * which is how to check a production .env from somewhere safe:
 *
 *   APP_ENV=production php artisan app:preflight
 */
class Preflight extends Command
{
    protected $signature = 'app:preflight
        {--strict : Fail on any problem, whatever APP_ENV says}';

    protected $description = 'Check that nothing production depends on is missing or pretending';

    public function handle(): int
    {
        $problems = Checks::problems();
        $enforced = $this->option('strict') || $this->laravel->environment('production');

        if ($problems === []) {
            $this->info('Ready. Nothing here would stop production from starting.');

            return self::SUCCESS;
        }

        $this->line($enforced
            ? '<error> Not ready. </error> Production will not start until these are fixed:'
            : 'APP_ENV is '.$this->laravel->environment().', so nothing is refused here. In production these would be:');

        foreach ($problems as $variable => $why) {
            $this->line("  <comment>{$variable}</comment> {$why}");
        }

        return $enforced ? self::FAILURE : self::SUCCESS;
    }
}
