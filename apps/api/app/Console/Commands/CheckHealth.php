<?php

namespace App\Console\Commands;

use App\Support\Operations\Readiness;
use Illuminate\Console\Command;

/**
 * The readiness check, from inside a container.
 *
 * What the compose file's healthchecks run: the API asks for the database,
 * the cache and the disks; the worker for the queue; the scheduler for itself.
 * Each container is then unhealthy for the thing it is responsible for, and
 * not for somebody else's. `--only=backup` asks the backup target how old the
 * newest backup is, which nothing asks over the web.
 */
class CheckHealth extends Command
{
    protected $signature = 'app:health
        {--only=* : Just these: database, cache, queue, storage, scheduler, backup}';

    protected $description = 'Check that the database, cache, queue, disks and scheduler are working';

    public function handle(Readiness $readiness): int
    {
        $known = [...Readiness::CHECKS, ...Readiness::OPTIONAL];
        $only = array_values(array_unique(array_merge(...array_map(
            fn (string $value) => array_filter(array_map('trim', explode(',', $value))),
            (array) $this->option('only'),
        ))));

        if ($unknown = array_diff($only, $known)) {
            $this->error('Not a check: '.implode(', ', $unknown).'. The checks are '.implode(', ', $known).'.');

            return self::INVALID;
        }

        $results = $readiness->run($only === [] ? null : $only);

        foreach ($results as $name => $result) {
            $result['ok']
                ? $this->line("  <info>ok</info>      {$name}")
                : $this->line("  <error>failing</error> {$name}: {$result['detail']}");
        }

        return Readiness::healthy($results) ? self::SUCCESS : self::FAILURE;
    }
}
