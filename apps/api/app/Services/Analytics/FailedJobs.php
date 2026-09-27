<?php

namespace App\Services\Analytics;

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;

/**
 * The two things staff may do to a queue job that gave up: run it again, or
 * let it go.
 *
 * Administrators only — a retried job sends its mail again, and a forgotten
 * one is gone from the only record that it failed. Each is written to the
 * audit trail under the administrator's name, with the job's class, queue
 * and when it failed; never its payload, which is a serialized job and may
 * carry a buyer's details.
 */
class FailedJobs
{
    public function __construct(
        private readonly FailedJobProviderInterface $failer,
        private readonly OperationsHealth $health,
        private readonly Auditor $auditor,
    ) {}

    public static function mayManage(?User $staff): bool
    {
        return $staff?->hasPlatformRole(PlatformRole::Admin) ?? false;
    }

    /** Put the job back on its queue. False when it was no longer there to retry. */
    public function retry(string $uuid, User $staff): bool
    {
        $job = $this->authorizedJob($uuid, $staff);

        if ($job === null) {
            return false;
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        if ($this->failer->find($uuid) !== null) {
            return false;
        }

        $this->record('queue.job_retried', $job, $staff);

        return true;
    }

    /** Delete the failure record without running the job. */
    public function forget(string $uuid, User $staff): bool
    {
        $job = $this->authorizedJob($uuid, $staff);

        if ($job === null || ! $this->failer->forget($uuid)) {
            return false;
        }

        $this->record('queue.job_forgotten', $job, $staff);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function authorizedJob(string $uuid, User $staff): ?array
    {
        if (! self::mayManage($staff)) {
            throw new AuthorizationException('Only administrators may retry or forget failed jobs.');
        }

        return $this->health->failedJobsByUuid([$uuid])[$uuid] ?? null;
    }

    /** @param array<string, mixed> $job */
    private function record(string $action, array $job, User $staff): void
    {
        $this->auditor->record($action, actor: $staff, metadata: [
            'failed_job' => $job['uuid'],
            'job' => $job['job_class'] ?? $job['job'],
            'queue' => $job['queue'],
            'connection' => $job['connection'],
            'failed_at' => $job['failed_at'],
        ]);
    }
}
