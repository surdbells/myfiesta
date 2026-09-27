<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether the machinery behind the platform is keeping up.
 *
 * Only what the platform actually records. Failed queue jobs, webhook
 * deliveries that gave up, numbers that said STOP, privacy requests and
 * identity documents waiting on a person, payout requests waiting on money,
 * and the schedule the jobs run to. Where something is not recorded — the
 * scheduler writes no heartbeat — this says so rather than inventing one.
 *
 * Read fresh on every visit: these are queues somebody is about to work
 * through, and a two-minute-old count of them is the wrong number. The one
 * thing kept is the schedule's definition, which only a deploy changes; when
 * each task is next due is still worked out on every visit.
 */
class OperationsHealth
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'failed_jobs' => $this->failedJobs(),
            'webhooks' => $this->webhooks(),
            'sms' => $this->smsSuppression(),
            'data_requests' => $this->dataRequests(),
            'identity' => $this->identityDocuments(),
            'payouts' => $this->payoutRequests(),
        ];
    }

    public function failedJobsTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    /** @return array{present: bool, count: int, queues: array<string, int>, latest: ?string} */
    public function failedJobs(): array
    {
        if (! Schema::hasTable($this->failedJobsTable())) {
            return ['present' => false, 'count' => 0, 'queues' => [], 'latest' => null];
        }

        $queues = DB::table($this->failedJobsTable())
            ->groupBy('queue')
            ->selectRaw('queue, count(*) as jobs')
            ->orderByDesc('jobs')
            ->pluck('jobs', 'queue')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'present' => true,
            'count' => array_sum($queues),
            'queues' => $queues,
            'latest' => DB::table($this->failedJobsTable())->max('failed_at'),
        ];
    }

    /**
     * One page of failed jobs, for the table.
     *
     * The payload is never shown — it is a serialized job and may carry a
     * buyer's details. The job's class name is read out of it, and the
     * exception is cut to its first line.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function failedJobPage(?string $search, ?string $queue, int $page, int $perPage, string $direction = 'desc'): LengthAwarePaginator
    {
        if (! Schema::hasTable($this->failedJobsTable())) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $query = $this->failedJobQuery($search, $queue);

        $total = (clone $query)->count();

        $rows = $this->rows($query
            ->orderBy('failed_at', $direction === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', $direction === 'asc' ? 'asc' : 'desc')
            ->forPage($page, $perPage));

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * Failed jobs by their uuids, in the same shape as a page of the table —
     * what a bulk action selected across pages resolves to.
     *
     * @param  list<string>  $uuids
     * @return array<string, array<string, mixed>>
     */
    public function failedJobsByUuid(array $uuids): array
    {
        if ($uuids === [] || ! Schema::hasTable($this->failedJobsTable())) {
            return [];
        }

        return $this->rows(DB::table($this->failedJobsTable())->whereIn('uuid', array_values($uuids))->orderByDesc('failed_at'));
    }

    /**
     * Every failed job the table's search and queue filter match, less the
     * ones unticked — what "select all" across pages resolves to.
     *
     * @param  list<string>  $exceptUuids
     * @return array<string, array<string, mixed>>
     */
    public function failedJobsMatching(?string $search, ?string $queue, array $exceptUuids = []): array
    {
        if (! Schema::hasTable($this->failedJobsTable())) {
            return [];
        }

        return $this->rows($this->failedJobQuery($search, $queue)
            ->when($exceptUuids !== [], fn ($q) => $q->whereNotIn('uuid', array_values($exceptUuids)))
            ->orderByDesc('failed_at')
            ->orderByDesc('id'));
    }

    /** The table's search and queue filter, the same for a page as for "select all". */
    private function failedJobQuery(?string $search, ?string $queue): Builder
    {
        return DB::table($this->failedJobsTable())
            ->when(filled($queue), fn ($q) => $q->where('queue', $queue))
            ->when(filled($search), fn ($q) => $q->where(fn ($q) => $q
                ->where('queue', 'ilike', '%'.$search.'%')
                ->orWhere('uuid', 'ilike', '%'.$search.'%')
                ->orWhereRaw(self::DISPLAY_NAME.' ilike ?', ['%'.$search.'%'])
                ->orWhereRaw('left(exception, 500) ilike ?', ['%'.$search.'%'])));
    }

    /** The job's class, read out of its serialized payload without unserializing it. */
    private const DISPLAY_NAME = "substring(payload from '\"displayName\":\"([^\"]+)\"')";

    /** @return array<string, array<string, mixed>> */
    private function rows(Builder $query): array
    {
        return $query
            ->select('id', 'uuid', 'connection', 'queue', 'failed_at')
            ->selectRaw(self::DISPLAY_NAME.' as job')
            ->selectRaw('left(exception, 500) as exception')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'job' => $row->job ? class_basename(str_replace('\\\\', '\\', $row->job)) : 'Unknown job',
                'job_class' => $row->job ? str_replace('\\\\', '\\', $row->job) : null,
                'exception' => trim(strtok((string) $row->exception, "\n") ?: ''),
                'failed_at' => (string) $row->failed_at,
            ])
            ->keyBy('uuid')
            ->all();
    }

    /**
     * Webhook deliveries that gave up, by the endpoint they were for.
     *
     * The address is shown as its host only: the rest of a receiver's URL is
     * where people put tokens. The endpoint is named by the start of its id.
     *
     * @return array{failed: int, retrying: int, disabled_endpoints: int, endpoints: list<array<string, mixed>>}
     */
    public function webhooks(int $limit = 10): array
    {
        $totals = DB::table('webhook_deliveries')
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->selectRaw("count(*) filter (where status = 'pending' and attempts > 0) as retrying")
            ->first();

        $endpoints = DB::table('webhook_deliveries')
            ->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->join('organizations', 'organizations.id', '=', 'webhook_endpoints.organization_id')
            ->where('webhook_deliveries.status', 'failed')
            ->groupBy('webhook_endpoints.id', 'webhook_endpoints.url', 'webhook_endpoints.disabled_at', 'webhook_endpoints.consecutive_failures', 'organizations.name')
            ->selectRaw('webhook_endpoints.id as id, webhook_endpoints.url as url, webhook_endpoints.disabled_at as disabled_at')
            ->selectRaw('webhook_endpoints.consecutive_failures as consecutive_failures, organizations.name as organization')
            ->selectRaw('count(*) as failures, max(webhook_deliveries.updated_at) as last_failed_at')
            ->orderByDesc('failures')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'ref' => substr((string) $row->id, 0, 8),
                'url' => self::safeUrl((string) $row->url),
                'organization' => (string) $row->organization,
                'failures' => (int) $row->failures,
                'consecutive_failures' => (int) $row->consecutive_failures,
                'disabled' => $row->disabled_at !== null,
                'last_failed_at' => (string) $row->last_failed_at,
            ])
            ->all();

        return [
            'failed' => (int) $totals->failed,
            'retrying' => (int) $totals->retrying,
            'disabled_endpoints' => DB::table('webhook_endpoints')->whereNotNull('disabled_at')->count(),
            'endpoints' => $endpoints,
        ];
    }

    /** @return array{present: bool, numbers: int, last_30_days: int} */
    public function smsSuppression(): array
    {
        if (! Schema::hasTable('phone_preferences')) {
            return ['present' => false, 'numbers' => 0, 'last_30_days' => 0];
        }

        $row = DB::table('phone_preferences')
            ->whereNotNull('opted_out_at')
            ->selectRaw('count(*) as numbers')
            ->selectRaw("count(*) filter (where opted_out_at >= now() - interval '30 days') as recent")
            ->first();

        return ['present' => true, 'numbers' => (int) $row->numbers, 'last_30_days' => (int) $row->recent];
    }

    /**
     * Privacy requests waiting on somebody.
     *
     * Verified requests are the ones the law's thirty days are running on;
     * unverified ones are waiting on the requester to follow their link.
     *
     * @return array{awaiting_proof: int, to_action: int, overdue: int, next_due: ?string}
     */
    public function dataRequests(): array
    {
        $row = DB::table('data_requests')
            ->selectRaw("count(*) filter (where status = 'pending') as awaiting_proof")
            ->selectRaw("count(*) filter (where status = 'verified') as to_action")
            ->selectRaw("count(*) filter (where status = 'verified' and due_at < now()) as overdue")
            ->selectRaw("min(due_at) filter (where status = 'verified') as next_due")
            ->first();

        return [
            'awaiting_proof' => (int) $row->awaiting_proof,
            'to_action' => (int) $row->to_action,
            'overdue' => (int) $row->overdue,
            'next_due' => $row->next_due,
        ];
    }

    /** @return array{pending: int, oldest: ?string} */
    public function identityDocuments(): array
    {
        $row = DB::table('organization_identity_documents')
            ->where('review_status', 'pending')
            ->selectRaw('count(*) as pending, min(created_at) as oldest')
            ->first();

        return ['pending' => (int) $row->pending, 'oldest' => $row->oldest];
    }

    /**
     * Payout requests waiting, per currency — never one total across them.
     *
     * @return array{count: int, currencies: array<string, array{count: int, amount: int, oldest: ?string}>}
     */
    public function payoutRequests(): array
    {
        $rows = DB::table('payout_requests')
            ->where('status', 'pending')
            ->groupBy('currency')
            ->selectRaw('currency, count(*) as count, coalesce(sum(amount), 0) as amount, min(created_at) as oldest')
            ->orderBy('currency')
            ->get();

        return [
            'count' => (int) $rows->sum('count'),
            'currencies' => $rows->mapWithKeys(fn ($row) => [(string) $row->currency => [
                'count' => (int) $row->count,
                'amount' => (int) $row->amount,
                'oldest' => $row->oldest,
            ]])->all(),
        ];
    }

    /**
     * What the scheduler is set to run, and when each is next due.
     *
     * Read from the schedule definition itself. The definition only changes
     * with a deploy, so it is kept for ten minutes; when each task is next
     * due is worked out from it on every visit, so an every-minute task never
     * shows a time already gone. When each task last ran is not recorded
     * anywhere — there is no heartbeat — so it is reported as unknown rather
     * than guessed from the next due time.
     *
     * @return array{tasks: list<array{command: string, expression: string, next_due: ?string, description: ?string}>, last_run_recorded: bool, error: ?string}
     */
    public function schedule(): array
    {
        $key = 'analytics:v1:operations.schedule-definition';
        $definition = Cache::get($key);

        if (! is_array($definition)) {
            $definition = $this->readSchedule();

            // A schedule that could not be read is tried again next visit.
            if ($definition['error'] === null) {
                Cache::put($key, $definition, 600);
            }
        }

        $now = CarbonImmutable::now('UTC');

        return [
            'tasks' => array_map(fn (array $task): array => [
                'command' => $task['command'],
                'expression' => $task['expression'],
                'next_due' => self::nextDue($task['expression'], $now),
                'description' => $task['description'],
            ], $definition['tasks']),
            'last_run_recorded' => false,
            'error' => $definition['error'],
        ];
    }

    /** @return array{tasks: list<array{command: string, expression: string, description: ?string}>, error: ?string} */
    private function readSchedule(): array
    {
        try {
            // Expressions converted to UTC, the clock the next due time is read on.
            Artisan::call('schedule:list', ['--json' => true, '--timezone' => 'UTC']);
            $tasks = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return ['tasks' => [], 'error' => 'The schedule could not be read: '.class_basename($e)];
        }

        return [
            'tasks' => collect(is_array($tasks) ? $tasks : [])
                ->map(fn (array $task) => [
                    'command' => trim(str_replace(['php artisan', "'artisan'"], '', (string) ($task['command'] ?? ''))),
                    'expression' => (string) ($task['expression'] ?? ''),
                    'description' => $task['description'] ?? null,
                ])
                ->values()
                ->all(),
            'error' => null,
        ];
    }

    /** The next time a UTC cron expression is due after now, or null if it cannot be read. */
    public static function nextDue(string $expression, CarbonImmutable $now): ?string
    {
        try {
            return CarbonImmutable::instance((new CronExpression($expression))->getNextRunDate($now))
                ->utc()
                ->format('Y-m-d H:i:sP');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Scheme, host and port — never the path, query string or credentials.
     *
     * Receivers put their secret in the path as often as in the query
     * (hooks.example.com/hooks/123/abc…), so nothing after the host is shown;
     * "/…" says something was left off. The endpoint's own id tells two
     * endpoints on one host apart.
     */
    public static function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return 'Unreadable address';
        }

        $withheld = trim($parts['path'] ?? '', '/') !== '' || isset($parts['query']) || isset($parts['fragment']);

        return ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($withheld ? '/…' : '');
    }
}
