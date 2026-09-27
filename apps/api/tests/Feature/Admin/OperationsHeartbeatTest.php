<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Pages\OperationsHealth;
use App\Services\Analytics\OperationsHealth as OperationsHealthService;
use App\Support\Operations\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The scheduler and the queue worker on Operations health.
 *
 * The page used to say the scheduler recorded no heartbeat, long after
 * app:heartbeat started writing one every minute — so the one place staff
 * look to see whether the platform is keeping up could not say whether the
 * two processes that fail silently were running. It reads the same
 * heartbeats as GET /api/health/ready, against the same limits, and says
 * when each is late.
 */
class OperationsHeartbeatTest extends TestCase
{
    use AnalyticsFixtures, RefreshDatabase;

    public function test_neither_seen_is_late_not_fine(): void
    {
        $this->stopTheClock();

        $beats = app(OperationsHealthService::class)->heartbeats();

        foreach ([Heartbeat::SCHEDULER, Heartbeat::QUEUE] as $name) {
            $this->assertTrue($beats[$name]['readable'], $name);
            $this->assertFalse($beats[$name]['seen'], $name);
            $this->assertNull($beats[$name]['last_seen_at'], $name);
            $this->assertTrue($beats[$name]['late'], $name);
        }
    }

    public function test_each_is_late_after_the_readiness_checks_own_limit(): void
    {
        $this->stopTheClock();
        $health = app(OperationsHealthService::class);

        Heartbeat::beat(Heartbeat::SCHEDULER);
        Heartbeat::beat(Heartbeat::QUEUE);

        $fresh = $health->heartbeats();
        $this->assertFalse($fresh[Heartbeat::SCHEDULER]['late']);
        $this->assertFalse($fresh[Heartbeat::QUEUE]['late']);
        $this->assertSame('2026-09-20 12:00:00+00:00', $fresh[Heartbeat::SCHEDULER]['last_seen_at']);
        $this->assertSame(180, $fresh[Heartbeat::SCHEDULER]['late_after']);
        $this->assertSame(600, $fresh[Heartbeat::QUEUE]['late_after']);

        // Four minutes: past the scheduler's three, inside the queue's ten.
        $this->travelTo($this->now->addMinutes(4));
        $later = $health->heartbeats();
        $this->assertTrue($later[Heartbeat::SCHEDULER]['late']);
        $this->assertSame(240, $later[Heartbeat::SCHEDULER]['seconds_ago']);
        $this->assertFalse($later[Heartbeat::QUEUE]['late']);

        $this->travelTo($this->now->addMinutes(11));
        $this->assertTrue($health->heartbeats()[Heartbeat::QUEUE]['late']);

        // The scheduler comes back; the worker is still late on its own.
        Heartbeat::beat(Heartbeat::SCHEDULER);
        $back = $health->heartbeats();
        $this->assertFalse($back[Heartbeat::SCHEDULER]['late']);
        $this->assertTrue($back[Heartbeat::QUEUE]['late']);
    }

    public function test_a_cache_that_cannot_be_read_is_unknown_rather_than_fine(): void
    {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('Connection refused [tcp://redis.internal.example:6379]'));

        $beats = app(OperationsHealthService::class)->heartbeats();

        $this->assertFalse($beats[Heartbeat::SCHEDULER]['readable']);
        $this->assertTrue($beats[Heartbeat::SCHEDULER]['late']);
        $this->assertTrue($beats[Heartbeat::QUEUE]['late']);
    }

    public function test_the_page_shows_when_each_was_last_seen_and_flags_the_late_one(): void
    {
        $this->stopTheClock();
        $this->signIn($this->staffMember(PlatformRole::Support));

        Livewire::test(OperationsHealth::class)
            ->assertSee('Background processes')
            ->assertSeeInOrder(['Scheduler', 'Never', 'Never seen', 'Queue worker', 'Never', 'Never seen'])
            ->assertDontSee('writes no heartbeat');
        $this->assertSame(['Never seen', 'Never seen'], $this->states());

        Heartbeat::beat(Heartbeat::SCHEDULER);
        Heartbeat::beat(Heartbeat::QUEUE);
        $this->travelTo($this->now->addMinutes(2));

        Livewire::test(OperationsHealth::class)
            ->assertSeeInOrder(['Scheduler', '2 minutes ago', '3 minutes', 'Running', 'Queue worker', '2 minutes ago', '10 minutes', 'Running']);
        $this->assertSame(['Running', 'Running'], $this->states());

        // The scheduler stops. Both go late, and the worker's row points at
        // the scheduler, which is what sends it the job.
        $this->travelTo($this->now->addMinutes(12));

        Livewire::test(OperationsHealth::class)
            ->assertSeeInOrder(['Scheduler', '12 minutes ago', 'Late', 'Queue worker', '12 minutes ago', 'Late'])
            ->assertSee('The scheduler is late too, and it is what sends this job: start there.');
        $this->assertSame(['Late', 'Late'], $this->states());

        // Only the worker stopped: it is late alone, with nothing to blame.
        Heartbeat::beat(Heartbeat::SCHEDULER);

        Livewire::test(OperationsHealth::class)
            ->assertSeeInOrder(['Scheduler', 'just now', 'Running', 'Queue worker', '12 minutes ago', 'Late'])
            ->assertDontSee('The scheduler is late too');
        $this->assertSame(['Running', 'Late'], $this->states());
    }

    /** What the page calls the scheduler and the worker, in that order. */
    private function states(): array
    {
        return array_column((new OperationsHealth)->summary()['processes'], 'state');
    }
}
