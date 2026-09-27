<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Support\Operations\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * GET /api/health/ready, and the same checks from inside a container.
 *
 * /up says PHP answered. This says whether an order can be taken and seen
 * through: the database, the cache, both disks, a worker taking jobs and the
 * scheduler running. Each case below is one of those going wrong, and each
 * has to come back 503 naming it — in words that never carry a host name or
 * an exception's message, because anybody can ask.
 */
class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disks of the test's own, so nothing is written into storage/.
        Storage::fake('private');
        Storage::fake('public');
    }

    private function allAlive(): void
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);
        Heartbeat::beat(Heartbeat::QUEUE);
    }

    public function test_everything_working_answers_200_with_every_check(): void
    {
        $this->allAlive();

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'status' => 'ok',
                'checks' => [
                    'database' => ['ok' => true],
                    'cache' => ['ok' => true],
                    'queue' => ['ok' => true],
                    'storage' => ['ok' => true],
                    'scheduler' => ['ok' => true],
                ],
            ]);

        // It leaves nothing behind on either disk.
        $this->assertSame([], Storage::disk('private')->allFiles('.health'));
        $this->assertSame([], Storage::disk('public')->allFiles('.health'));
    }

    public function test_a_scheduler_that_stopped_is_named(): void
    {
        $this->allAlive();
        $this->travel(10)->minutes();
        Heartbeat::beat(Heartbeat::QUEUE);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'failing')
            ->assertJsonPath('checks.scheduler', ['ok' => false, 'detail' => 'The scheduler has not run for 10 minutes.'])
            ->assertJsonPath('checks.queue', ['ok' => true]);
    }

    public function test_no_worker_is_named_whether_it_stopped_or_never_started(): void
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue.detail', 'No worker has been seen running a job.');

        Heartbeat::beat(Heartbeat::QUEUE);
        $this->travel(15)->minutes();
        Heartbeat::beat(Heartbeat::SCHEDULER);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue.detail', 'No worker has run a job for 15 minutes.');
    }

    public function test_a_worker_failing_every_job_is_as_bad_as_none(): void
    {
        $this->allAlive();

        foreach (range(1, 10) as $i) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Swift_TransportException: Connection refused',
                'failed_at' => now()->subMinutes($i),
            ]);
        }

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue.detail', 'At least 10 jobs failed in the last hour.');
    }

    public function test_a_database_that_does_not_answer_says_so_without_saying_where_it_is(): void
    {
        $this->allAlive();
        $default = config('database.default');

        config([
            'database.connections.unreachable' => array_merge(config("database.connections.{$default}"), [
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'the_name_of_the_live_database',
                'username' => 'a_user_nobody_should_learn',
            ]),
            'database.default' => 'unreachable',
        ]);

        try {
            $response = $this->getJson('/api/health/ready');
        } finally {
            // Back before the test's own transaction is rolled back on it.
            config(['database.default' => $default]);
        }

        $response->assertStatus(503)
            ->assertJsonPath('checks.database', ['ok' => false, 'detail' => 'The database did not answer.']);

        foreach (['127.0.0.1', 'the_name_of_the_live_database', 'a_user_nobody_should_learn', 'SQLSTATE', 'Exception'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_a_cache_that_keeps_nothing_is_named(): void
    {
        config(['cache.stores.forgetful' => ['driver' => 'null'], 'cache.default' => 'forgetful']);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.cache', ['ok' => false, 'detail' => 'The cache did not keep what was written to it.']);
    }

    /**
     * Redis gone, the way the redis driver goes: every call throws. In
     * production the rate limits and maintenance mode are kept there too
     * (APP_MAINTENANCE_DRIVER=cache, .env.production.example), so both are
     * moved onto it here.
     */
    private function cacheUnreachable(): void
    {
        Cache::extend('unreachable', fn () => Cache::repository(new class implements Store
        {
            public function get($key)
            {
                self::refuse();
            }

            public function many(array $keys)
            {
                self::refuse();
            }

            public function put($key, $value, $seconds)
            {
                self::refuse();
            }

            public function putMany(array $values, $seconds)
            {
                self::refuse();
            }

            public function increment($key, $value = 1)
            {
                self::refuse();
            }

            public function decrement($key, $value = 1)
            {
                self::refuse();
            }

            public function forever($key, $value)
            {
                self::refuse();
            }

            public function touch($key, $seconds)
            {
                self::refuse();
            }

            public function forget($key)
            {
                self::refuse();
            }

            public function flush()
            {
                self::refuse();
            }

            public function getPrefix()
            {
                return '';
            }

            private static function refuse(): never
            {
                throw new RuntimeException('Connection refused [tcp://redis.internal.example:6379]');
            }
        }));

        config([
            'cache.stores.unreachable' => ['driver' => 'unreachable'],
            'cache.default' => 'unreachable',
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'unreachable',
        ]);

        // Both were made on the working cache when the application booted.
        $this->app->forgetInstance(RateLimiter::class);
        $this->app->forgetInstance(MaintenanceModeManager::class);
    }

    public function test_a_cache_that_does_not_answer_is_named_rather_than_answered_with_a_500(): void
    {
        $this->cacheUnreachable();

        $response = $this->getJson('/api/health/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'failing')
            ->assertJsonPath('checks.cache', ['ok' => false, 'detail' => 'The cache did not answer.'])
            ->assertJsonPath('checks.database', ['ok' => true])
            ->assertJsonPath('checks.storage', ['ok' => true]);

        foreach (['redis.internal', 'Connection refused', 'Exception'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        // Still what the load balancer routes on: PHP is serving.
        $this->get('/up')->assertOk();

        // Everything else is refused while nobody can say whether the
        // platform is in maintenance, rather than let through on a guess.
        $this->getJson('/api/discover')->assertStatus(500);
    }

    public function test_in_maintenance_mode_it_is_refused_like_everything_else(): void
    {
        $this->allAlive();
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['status' => 503]);

        $this->getJson('/api/health/ready')->assertStatus(503)->assertJsonMissingPath('checks');
        $this->get('/up')->assertOk();

        $this->app->maintenanceMode()->deactivate();

        $this->getJson('/api/health/ready')->assertOk();
    }

    public function test_the_scheduler_keeps_its_heartbeat_through_maintenance_mode(): void
    {
        // On the hour, when every-five-minutes work is due as well.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00', 'UTC'));
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);

        $due = fn () => collect(app(Schedule::class)->dueEvents($this->app))->map(fn ($event) => (string) $event->command);
        $runs = fn (string $command) => $due()->contains(fn (string $line) => str_contains($line, $command));

        $this->assertTrue($runs('app:heartbeat'));
        $this->assertTrue($runs('campaigns:send'));

        // The scheduler is alive through `php artisan down`, and the check
        // that asks whether it is must be able to say so — a switch-over is
        // checked while the platform is still down.
        $this->app->maintenanceMode()->activate(['status' => 503]);

        $this->assertTrue($runs('app:heartbeat'));
        $this->assertFalse($runs('campaigns:send'), 'Everything else waits for `up`.');

        $this->app->maintenanceMode()->deactivate();
    }

    public function test_a_disk_that_takes_no_files_is_named(): void
    {
        $this->allAlive();

        // A disk rooted inside a file: nothing can ever be written beneath it.
        config([
            'filesystems.disks.full' => ['driver' => 'local', 'root' => base_path('artisan'), 'throw' => false],
            'operations.health.disks' => ['private', 'full'],
        ]);

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.storage.ok', false)
            ->assertJsonPath('checks.database', ['ok' => true]);
    }

    public function test_it_is_rate_limited(): void
    {
        $this->allAlive();

        foreach (range(1, 30) as $i) {
            $this->getJson('/api/health/ready')->assertOk();
        }

        $this->getJson('/api/health/ready')->assertStatus(429);
    }

    public function test_the_scheduler_writes_its_heartbeat_and_sends_the_queue_one(): void
    {
        Queue::fake();

        $this->artisan('app:heartbeat')->assertSuccessful();

        $this->assertSame(0, Heartbeat::secondsSince(Heartbeat::SCHEDULER));
        $this->assertNull(Heartbeat::secondsSince(Heartbeat::QUEUE), 'Only a worker running the job may say a worker is alive.');
        Queue::assertPushed(QueueHeartbeat::class);

        (new QueueHeartbeat)->handle();

        $this->assertSame(0, Heartbeat::secondsSince(Heartbeat::QUEUE));
    }

    public function test_each_container_can_be_asked_about_its_own_part(): void
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);

        $this->artisan('app:health', ['--only' => ['scheduler']])->assertSuccessful();
        $this->artisan('app:health', ['--only' => ['database,cache,storage']])->assertSuccessful();

        $this->artisan('app:health', ['--only' => ['queue']])
            ->expectsOutputToContain('No worker has been seen running a job.')
            ->assertFailed();

        $this->artisan('app:health', ['--only' => ['everything']])
            ->expectsOutputToContain('Not a check: everything.')
            ->assertExitCode(2);
    }
}
