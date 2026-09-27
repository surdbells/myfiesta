<?php

namespace App\Support\Operations;

use App\Services\Backups\BackupStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Whether everything a sale depends on is working, one part at a time.
 *
 * /up only says PHP answered. A platform can answer /up all night with the
 * database gone, or with no worker running — every order paid and nobody sent
 * a ticket. This asks each part in turn: the database answers, the cache keeps
 * what it is given, both disks take a file, a worker has run a job lately and
 * the scheduler has run lately.
 *
 * What it says is fixed wording, never an exception's message: a failed
 * connection names the host it failed to reach, and this is answered to
 * anybody who asks. The exception goes to the log, where the operator is.
 */
final class Readiness
{
    /** What GET /api/health/ready asks, in the order it answers. */
    public const CHECKS = ['database', 'cache', 'queue', 'storage', 'scheduler'];

    /**
     * Also available to `php artisan app:health --only=backup`, and not asked
     * over the web: a missed backup is worth an alert, not a load balancer
     * taking the site away.
     */
    public const OPTIONAL = ['backup'];

    public function __construct(private readonly BackupStore $backups) {}

    /**
     * @param  list<string>|null  $only
     * @return array<string, array{ok: bool, detail?: string}>
     */
    public function run(?array $only = null): array
    {
        $results = [];

        foreach ($only ?? self::CHECKS as $name) {
            try {
                $detail = match ($name) {
                    'database' => $this->database(),
                    'cache' => $this->cache(),
                    'queue' => $this->queue(),
                    'storage' => $this->storage(),
                    'scheduler' => $this->scheduler(),
                    'backup' => $this->backup(),
                };
            } catch (Throwable $e) {
                Log::warning("Readiness: the {$name} check failed.", ['exception' => $e]);

                $detail = self::UNREACHABLE[$name] ?? 'Could not be checked.';
            }

            $results[$name] = $detail === null ? ['ok' => true] : ['ok' => false, 'detail' => $detail];
        }

        return $results;
    }

    /** @param  array<string, array{ok: bool}>  $results */
    public static function healthy(array $results): bool
    {
        return collect($results)->every(fn (array $result) => $result['ok']);
    }

    /** What is said when a check threw, in place of whatever it threw. */
    private const UNREACHABLE = [
        'database' => 'The database did not answer.',
        'cache' => 'The cache did not answer.',
        'queue' => 'The queue could not be read.',
        'storage' => 'A file could not be written.',
        'scheduler' => 'The cache did not answer, so the scheduler could not be checked.',
        'backup' => 'The backup target could not be read.',
    ];

    private function database(): ?string
    {
        DB::select('select 1');

        return null;
    }

    private function cache(): ?string
    {
        $key = 'ops:ready:'.Str::random(12);
        $value = Str::random(12);

        Cache::put($key, $value, 30);
        $kept = Cache::get($key) === $value;
        Cache::forget($key);

        return $kept ? null : 'The cache did not keep what was written to it.';
    }

    /**
     * A worker ran the heartbeat recently, and jobs are not failing in bulk.
     *
     * A worker that is alive and failing every email is as bad as none, and
     * only the second half of this sees it.
     */
    private function queue(): ?string
    {
        $seconds = Heartbeat::secondsSince(Heartbeat::QUEUE);

        if ($seconds === null) {
            return 'No worker has been seen running a job.';
        }

        if ($seconds > (int) config('operations.health.queue_stale_after')) {
            return 'No worker has run a job for '.self::minutes($seconds).'.';
        }

        $table = (string) config('queue.failed.table', 'failed_jobs');
        $limit = (int) config('operations.health.failed_jobs_per_hour');

        if (Schema::hasTable($table)) {
            $failed = DB::table($table)->where('failed_at', '>=', now()->subHour())->count();

            // The threshold, never the count: how much is going wrong is the
            // admin's to read (Operations health), not a stranger's.
            if ($failed >= $limit) {
                return "At least {$limit} jobs failed in the last hour.";
            }
        }

        return null;
    }

    /**
     * Each disk takes a file and gives it back.
     *
     * Under a dot-directory, which the API's nginx refuses to serve, so the
     * instant the file exists on the public disk nobody can fetch it.
     */
    private function storage(): ?string
    {
        foreach ((array) config('operations.health.disks') as $name) {
            $disk = Storage::disk($name);
            $path = '.health/ready-'.Str::random(16);

            try {
                $written = $disk->put($path, 'ok') !== false && $disk->get($path) === 'ok';
            } finally {
                rescue(fn () => $disk->delete($path), report: false);
            }

            if (! $written) {
                return "The {$name} disk did not take a file.";
            }
        }

        return null;
    }

    private function scheduler(): ?string
    {
        $seconds = Heartbeat::secondsSince(Heartbeat::SCHEDULER);

        if ($seconds === null) {
            return 'The scheduler has not been seen running.';
        }

        return $seconds > (int) config('operations.health.scheduler_stale_after')
            ? 'The scheduler has not run for '.self::minutes($seconds).'.'
            : null;
    }

    /** Read from the target itself, which is where a restore would come from. */
    private function backup(): ?string
    {
        $latest = $this->backups->latest();

        if ($latest === null) {
            return 'There is no backup.';
        }

        $hours = (int) floor($latest->takenAt->diffInSeconds(now(), true) / 3600);

        return $hours >= (int) config('operations.backup.stale_after_hours')
            ? "The newest backup is {$hours} hours old."
            : null;
    }

    private static function minutes(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
    }
}
