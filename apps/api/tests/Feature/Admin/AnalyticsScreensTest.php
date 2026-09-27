<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EventPerformance;
use App\Filament\Pages\OperationsHealth;
use App\Filament\Pages\OrganizerPerformance;
use App\Filament\Widgets\CheckInRate;
use App\Filament\Widgets\OrderVolume;
use App\Filament\Widgets\PlatformKpis;
use App\Filament\Widgets\RevenueSplit;
use App\Filament\Widgets\SalesByChannel;
use App\Filament\Widgets\SalesByPlace;
use App\Filament\Widgets\SalesTrend;
use App\Filament\Widgets\TopEvents;
use App\Filament\Widgets\TopOrganizers;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Analytics\OperationsHealth as OperationsHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The performance and operations screens, rendered for real: who may open
 * them, that the charts on them draw the week's figures, that the listings
 * sort, search and filter, and that the only things they can change — a
 * failed job, retried or forgotten — are an administrator's, and audited.
 */
class AnalyticsScreensTest extends TestCase
{
    use AnalyticsFixtures, RefreshDatabase;

    private const WEEK = ['currency' => 'CAD', 'period' => '7d'];

    public function test_every_member_of_staff_can_read_the_performance_pages_and_nobody_else_can(): void
    {
        $this->stopTheClock();

        foreach (PlatformRole::cases() as $role) {
            $this->signIn($this->staffMember($role));

            foreach (['/admin', '/admin/insights/organizers', '/admin/insights/events'] as $path) {
                $this->get($path)->assertSuccessful();
            }
        }

        $this->signIn(User::factory()->create(['email_verified_at' => now()]));
        $this->get('/admin')->assertForbidden();
        $this->assertFalse(Dashboard::canAccess());
        $this->assertFalse(OrganizerPerformance::canAccess());
        $this->assertFalse(EventPerformance::canAccess());
    }

    public function test_operations_is_for_administrators_and_support_and_not_finance(): void
    {
        $this->stopTheClock();

        $this->signIn($this->staffMember(PlatformRole::Admin));
        $this->get('/admin/operations')->assertSuccessful()->assertSee('Scheduled jobs')->assertSee('Not recorded');

        $this->signIn($this->staffMember(PlatformRole::Support));
        $this->get('/admin/operations')->assertSuccessful();

        $this->signIn($this->staffMember(PlatformRole::Finance));
        $this->get('/admin/operations')->assertForbidden();
    }

    public function test_the_dashboard_replaces_filamnets_welcome_screen_with_the_pickers(): void
    {
        $this->stopTheClock();
        $this->signIn($this->staffMember(PlatformRole::Support));

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('Platform performance')
            ->assertSee('data-mf-charts', false)
            ->assertDontSee('filament-info-widget', false);

        Livewire::test(Dashboard::class)
            ->assertSet('filters.currency', 'CAD')
            ->assertSet('filters.period', '30d')
            ->set('filters.currency', 'NGN')
            ->assertSee('Africa/Lagos');
    }

    public function test_the_key_figures_are_the_weeks_and_say_which_way_they_moved(): void
    {
        $this->seedTrade();
        $this->signIn($this->staffMember(PlatformRole::Finance));

        Livewire::test(PlatformKpis::class, ['pageFilters' => self::WEEK])
            ->assertSee('$392.70')                  // gross
            ->assertSee('$3.00')                    // net platform take
            ->assertSee('$9.50')                    // gateway fees
            ->assertSee('1 payment not yet settled')
            ->assertSee('+565.6%')                  // 392.70 against 59.00
            ->assertSee('15.0% of gross sales')     // 59.00 refunded of 392.70
            ->assertSee('$190.00')                  // owed to organizers
            ->assertSee('data-stat="disputes-open"', false)
            ->assertDontSee('₦');
    }

    public function test_the_naira_market_shows_only_naira(): void
    {
        $this->seedTrade();
        $this->signIn($this->staffMember(PlatformRole::Admin));

        $html = Livewire::test(PlatformKpis::class, ['pageFilters' => ['currency' => 'NGN', 'period' => '7d']])
            ->assertSee('₦11,250')
            ->html();

        // No dollar amount at all. Not "$" alone: the page's own scripts
        // write that.
        $this->assertDoesNotMatchRegularExpression('/\$[\d,]+\.\d\d/', $html);
    }

    public function test_every_chart_on_the_dashboard_draws_the_week(): void
    {
        $this->seedTrade();
        $this->signIn($this->staffMember(PlatformRole::Support));

        $html = fn (string $widget): string => Livewire::test($widget, ['pageFilters' => self::WEEK])->html();

        $trend = $html(SalesTrend::class);
        $this->assertSame(1, substr_count($trend, 'class="mf-line"'));
        $this->assertSame(7, substr_count($trend, 'class="mf-hit"'), 'One hover target per day of the week.');
        $this->assertStringContainsString('Sep 17 — Gross sales: $106.70', $trend);

        $volume = $html(OrderVolume::class);
        $this->assertSame(8, substr_count($volume, 'class="mf-bar '), 'Four days with orders, each with an orders and a tickets bar.');

        $split = $html(RevenueSplit::class);
        $this->assertSame(4, substr_count($split, 'class="mf-seg '));
        $this->assertStringContainsString('Organizers keep: $290.00 (73.8%)', $split);
        $this->assertStringContainsString('data-chart="stacked-bar"', $split);
        // O4, sold on the 16th, was refunded in full the next day: nothing of it was kept.
        $this->assertStringContainsString('Sep 16 — Organizers: $0.00; Service charge: $0.00; Tax: $0.00', $split);
        $this->assertStringContainsString('Sep 17 — Organizers: $90.00; Service charge: $5.00; Tax: $11.70', $split);

        $channels = $html(SalesByChannel::class);
        $this->assertStringContainsString('Online: $342.70', $channels);
        $this->assertStringContainsString('At the door: $50.00', $channels);

        $organizers = $html(TopOrganizers::class);
        $this->assertSame(1, substr_count($organizers, 'class="mf-bar"'));
        $this->assertStringContainsString('Toronto Collective', $organizers);
        $this->assertStringContainsString('insights/organizers?filters', $organizers);

        $events = $html(TopEvents::class);
        $this->assertStringContainsString('Harvest Moon Party', $events);

        $places = $html(SalesByPlace::class);
        $this->assertStringContainsString('Toronto, CA', $places);
        $this->assertStringContainsString('Canada', $places);

        $checkIns = $html(CheckInRate::class);
        $this->assertStringContainsString('66.7%', $checkIns);
        $this->assertStringContainsString('6 / 9 (67%)', $checkIns);
        $this->assertSame(1, substr_count($checkIns, 'class="mf-track"'));
    }

    public function test_an_organizers_report_opens_from_the_picker(): void
    {
        $this->seedTrade();
        $this->signIn($this->staffMember(PlatformRole::Finance));

        $page = Livewire::test(OrganizerPerformance::class)
            ->set('filters.currency', 'CAD')
            ->set('filters.period', '7d')
            ->assertDontSee('Earned and paid out')
            ->set('filters.organization', $this->toronto->id)
            ->assertSee('Earned and paid out')
            ->assertSee('$190.00')                  // owed now
            ->assertSee('Payout of $50.00 requested')
            ->assertSee('Paid out in period')
            ->assertSee('33.3%')                    // repeat buyers
            // ada@ ordered twice this week and never before: more than once, not "before".
            ->assertSee('1 of 3 buyers have ordered from them more than once')
            ->assertDontSee('had bought from them before')
            ->assertSee('4.0%')                     // views to orders
            ->assertSee('5 / 120 (4%)')             // tickets against capacity
            ->assertTableColumnVisible('paid_out');

        $this->assertGreaterThanOrEqual(5, substr_count($page->html(), '<svg'));
    }

    /**
     * Payout requests and settlements are Finance's and administrators' —
     * their own screens refuse support — so no other screen shows support
     * the amounts either: that a payout was asked for, not how much, and
     * nothing of what was paid out.
     */
    public function test_support_sees_that_a_payout_was_asked_for_but_not_how_much_or_what_was_paid_out(): void
    {
        $this->seedTrade();

        $this->signIn($this->staffMember(PlatformRole::Support));

        Livewire::test(PlatformKpis::class, ['pageFilters' => self::WEEK])
            ->assertSee('Payout requests waiting')
            ->assertDontSee('$50.00');

        Livewire::test(OrganizerPerformance::class)
            ->set('filters.currency', 'CAD')
            ->set('filters.period', '7d')
            ->set('filters.organization', $this->toronto->id)
            ->assertSee('$190.00')                  // owed now: the balance support already sees
            ->assertSee('A payout has been requested')
            ->assertDontSee('$50.00 requested')     // $50.00 itself is Sep 16's net on the sales chart
            ->assertDontSee('Paid out')
            ->assertDontSee('Earned and paid out')
            ->assertTableColumnHidden('paid_out');

        Livewire::test(OperationsHealth::class)
            ->assertSee('1 in CAD')
            ->assertDontSee('$50.00');

        $this->signIn($this->staffMember(PlatformRole::Finance));

        Livewire::test(PlatformKpis::class, ['pageFilters' => self::WEEK])
            ->assertSee('$50.00 asked for');
    }

    public function test_the_organizer_listing_sorts_searches_and_filters(): void
    {
        $this->seedTrade();
        $this->signIn($this->staffMember(PlatformRole::Admin));

        $quiet = Organization::factory()->create(['name' => 'Quiet Rooms']);

        // A second dollar organizer: $200.00 sold this week, $100.00 of it
        // refunded — less than Toronto's $392.70 but a higher refund rate
        // (50% against 15%). Lagos sold nothing in dollars and Quiet Rooms
        // nothing at all, so both have no refund rate.
        $second = Organization::factory()->create(['name' => 'Second House']);
        $room = Event::factory()->published()->create(['organization_id' => $second->id, 'title' => 'Second Night', 'starts_at' => '2026-09-26 02:00:00']);
        $tier = TicketType::factory()->create(['event_id' => $room->id, 'name' => 'GA', 'price_amount' => 10000, 'quantity_available' => 50]);
        $order = $this->sale($room, $tier, 2, '2026-09-16 12:00:00', ['email' => 'kemi@example.com', 'fee' => 300]);
        DB::table('refunds')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'event_id' => $room->id,
            'organization_id' => $second->id,
            'currency' => 'CAD',
            'amount' => 10000,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'status' => 'succeeded',
            'reason' => 'requested_by_customer',
            'created_at' => '2026-09-18 12:00:00',
            'updated_at' => '2026-09-18 12:00:00',
        ]);

        Livewire::test(OrganizerPerformance::class)
            ->set('filters.currency', 'CAD')
            ->set('filters.period', '7d')
            ->assertCanSeeTableRecords([$this->toronto, $this->lagos, $quiet, $second])
            ->assertTableColumnFormattedStateSet('gross', '$392.70', $this->toronto)
            ->assertTableColumnFormattedStateSet('gross', '$200.00', $second)
            ->assertTableColumnFormattedStateSet('refund_rate', '15.0%', $this->toronto)
            ->assertTableColumnFormattedStateSet('refund_rate', '50.0%', $second)
            ->sortTable('gross', 'desc')
            ->assertCanSeeTableRecords([$this->toronto, $second, $quiet], inOrder: true)
            ->assertCanSeeTableRecords([$this->toronto, $second, $this->lagos], inOrder: true)
            ->sortTable('gross', 'asc')
            ->assertCanSeeTableRecords([$quiet, $second, $this->toronto], inOrder: true)
            ->assertCanSeeTableRecords([$this->lagos, $second, $this->toronto], inOrder: true)
            // Nulls last both ways: an organizer with no sales has no rate to rank.
            ->sortTable('refund_rate', 'desc')
            ->assertCanSeeTableRecords([$second, $this->toronto, $quiet], inOrder: true)
            ->assertCanSeeTableRecords([$second, $this->toronto, $this->lagos], inOrder: true)
            ->sortTable('refund_rate', 'asc')
            ->assertCanSeeTableRecords([$this->toronto, $second, $quiet], inOrder: true)
            ->assertCanSeeTableRecords([$this->toronto, $second, $this->lagos], inOrder: true)
            ->searchTable('quiet')
            ->assertCanSeeTableRecords([$quiet])
            ->assertCanNotSeeTableRecords([$this->toronto])
            ->searchTable(null)
            ->filterTable('sold', true)
            ->assertCanSeeTableRecords([$this->toronto])
            ->assertCanNotSeeTableRecords([$this->lagos, $quiet])
            ->resetTableFilters()
            ->filterTable('disputes', true)
            ->assertCanSeeTableRecords([$this->toronto])
            ->assertCanNotSeeTableRecords([$quiet]);
    }

    public function test_an_events_report_draws_every_chart(): void
    {
        $this->seedTrade();
        $this->signIn($this->staffMember(PlatformRole::Finance));

        $html = Livewire::test(EventPerformance::class)
            ->set('filters.event', $this->night->id)
            ->assertSee('Sales pace')
            ->assertSee('$451.70')
            ->assertSee('EARLY')
            ->assertSee('People admitted')
            ->assertSee($this->orders['O4']->reference)
            // Seven sold, O4's refunded and O6's voided among them; the room's
            // share is the five still valid, and the tile says so.
            ->assertSee('5 still valid, 4.2% of 120 places · 1 comp')
            ->html();

        $this->assertStringContainsString('22:15 — People admitted: 3', $html);
        $this->assertStringContainsString('Night — Tickets sold so far: 7', $html);
        $this->assertStringContainsString('GA: 6 / 100 (6%)', $html);

        // Ticket codes never appear on a list — only the discount code does.
        foreach (DB::table('tickets')->pluck('code') as $code) {
            $this->assertStringNotContainsString($code, $html);
        }
    }

    public function test_the_event_listing_sorts_searches_and_filters(): void
    {
        $this->seedTrade();
        $this->signIn($this->staffMember(PlatformRole::Support));

        Livewire::test(EventPerformance::class)
            ->assertCanSeeTableRecords([$this->night, $this->upcoming, $this->lagosNight])
            ->assertTableColumnFormattedStateSet('gross', '$451.70', $this->night)
            ->assertTableColumnFormattedStateSet('gross', '₦11,250', $this->lagosNight)
            ->assertTableColumnFormattedStateSet('check_in_rate', '66.7%', $this->night)
            // Money ranks inside each market, never kobo against cents: the
            // ₦11,250 night is 1,125,000 minor units and would otherwise
            // lead a $451.70 one.
            ->sortTable('gross', 'desc')
            ->assertCanSeeTableRecords([$this->night, $this->upcoming, $this->lagosNight], inOrder: true)
            ->sortTable('gross', 'asc')
            ->assertCanSeeTableRecords([$this->upcoming, $this->night, $this->lagosNight], inOrder: true)
            ->sortTable('refunded', 'desc')
            ->assertCanSeeTableRecords([$this->night, $this->upcoming, $this->lagosNight], inOrder: true)
            ->filterTable('currency', 'NGN')
            ->sortTable('gross', 'desc')
            ->assertCanSeeTableRecords([$this->lagosNight])
            ->assertCanNotSeeTableRecords([$this->night, $this->upcoming])
            ->resetTableFilters()
            ->searchTable('lagos nights')
            ->assertCanSeeTableRecords([$this->lagosNight])
            ->assertCanNotSeeTableRecords([$this->night])
            ->searchTable(null)
            ->filterTable('currency', 'CAD')
            ->assertCanNotSeeTableRecords([$this->lagosNight])
            ->resetTableFilters()
            ->filterTable('when', false)
            ->assertCanSeeTableRecords([$this->night])
            ->assertCanNotSeeTableRecords([$this->upcoming, $this->lagosNight]);
    }

    public function test_an_administrator_can_retry_and_forget_a_failed_job_and_both_are_audited(): void
    {
        $this->stopTheClock();
        Queue::fake();
        $admin = $this->signIn($this->staffMember(PlatformRole::Admin));

        $retry = $this->failedJob('mail');
        $forget = $this->failedJob('default');

        Livewire::test(OperationsHealth::class)
            ->assertCanSeeTableRecords([$retry, $forget])
            ->assertTableActionVisible('retry', $retry)
            ->callTableAction('retry', $retry)
            ->callTableAction('forget', $forget)
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('failed_jobs')->count());

        $retried = AuditLog::query()->where('action', 'queue.job_retried')->sole();
        $this->assertSame($admin->id, $retried->actor_id);
        $this->assertSame($retry, $retried->metadata['failed_job']);
        $this->assertSame('App\Mail\YourTicket', $retried->metadata['job']);
        $this->assertArrayNotHasKey('payload', $retried->metadata);

        $this->assertSame($admin->id, AuditLog::query()->where('action', 'queue.job_forgotten')->sole()->actor_id);
    }

    public function test_an_administrator_can_retry_the_ticked_failed_jobs_at_once_and_each_is_audited(): void
    {
        $this->stopTheClock();
        Queue::fake();
        $admin = $this->signIn($this->staffMember(PlatformRole::Admin));

        $a = $this->failedJob('mail');
        $b = $this->failedJob('mail');
        $left = $this->failedJob('default');

        Livewire::test(OperationsHealth::class)
            ->assertTableBulkActionVisible('retrySelected')
            ->callTableBulkAction('retrySelected', [$a, $b])
            ->assertHasNoErrors()
            ->assertNotified('2 jobs put back on their queues');

        $this->assertSame([$left], DB::table('failed_jobs')->pluck('uuid')->all());

        $audited = AuditLog::query()->where('action', 'queue.job_retried')->get();
        $this->assertEqualsCanonicalizing([$a, $b], $audited->map(fn (AuditLog $entry) => $entry->metadata['failed_job'])->all());
        $this->assertSame([$admin->id], $audited->pluck('actor_id')->unique()->values()->all());
    }

    public function test_select_all_retries_every_job_the_search_and_filter_match_except_the_unticked(): void
    {
        $this->stopTheClock();
        Queue::fake();
        $this->signIn($this->staffMember(PlatformRole::Admin));

        $a = $this->failedJob('mail');
        $b = $this->failedJob('mail');
        $unticked = $this->failedJob('mail');
        $otherQueue = $this->failedJob('default');

        // "Select all" across pages: Filament sends no keys, only the ones
        // unticked since, and the filter they were selected under.
        Livewire::test(OperationsHealth::class)
            ->filterTable('queue', 'mail')
            ->set('isTrackingDeselectedTableRecords', true)
            ->set('deselectedTableRecords', [$unticked])
            ->callTableBulkAction('retrySelected', [])
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing([$unticked, $otherQueue], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertEqualsCanonicalizing([$a, $b], AuditLog::query()->where('action', 'queue.job_retried')->get()
            ->map(fn (AuditLog $entry) => $entry->metadata['failed_job'])->all());
    }

    public function test_webhook_failures_name_the_receivers_host_and_never_its_path_or_query(): void
    {
        $this->stopTheClock();
        $this->signIn($this->staffMember(PlatformRole::Support));

        $organization = Organization::factory()->create(['name' => 'Hook Heavy']);
        $endpoint = (string) Str::uuid();

        DB::table('webhook_endpoints')->insert([
            'id' => $endpoint,
            'organization_id' => $organization->id,
            'url' => 'https://hooks.example.com/hooks/catch/123/s3cr3tPathToken/?token=qu3ryT0ken',
            'secret' => 'whsec_not_shown',
            'events' => json_encode(['order.paid']),
            'consecutive_failures' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('webhook_deliveries')->insert([
            'id' => (string) Str::uuid(),
            'webhook_endpoint_id' => $endpoint,
            'organization_id' => $organization->id,
            'event' => 'order.paid',
            'payload' => json_encode(['id' => 'x']),
            'status' => 'failed',
            'attempts' => 8,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        Livewire::test(OperationsHealth::class)
            ->assertSee('Hook Heavy')
            ->assertSee('https://hooks.example.com/…')
            ->assertSee('endpoint '.substr($endpoint, 0, 8))
            ->assertDontSee('s3cr3tPathToken')
            ->assertDontSee('qu3ryT0ken')
            ->assertDontSee('/hooks/catch');

        $this->assertSame('https://example.com:8443/…', OperationsHealthService::safeUrl('https://user:pass@example.com:8443/in/abc'));
        $this->assertSame('https://example.com', OperationsHealthService::safeUrl('https://example.com/'));
        $this->assertSame('https://example.com/…', OperationsHealthService::safeUrl('https://example.com?key=abc'));
        $this->assertSame('Unreadable address', OperationsHealthService::safeUrl('not an address'));
    }

    public function test_when_a_scheduled_job_is_next_due_is_worked_out_on_every_visit(): void
    {
        $this->stopTheClock();
        $health = app(OperationsHealthService::class);
        $retry = fn (array $schedule): array => collect($schedule['tasks'])->firstWhere('command', 'webhooks:retry');

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:30', 'UTC'));
        $first = $health->schedule();
        $this->assertNull($first['error']);
        $this->assertSame('* * * * *', $retry($first)['expression']);
        $this->assertSame('2026-09-20 12:01:00+00:00', $retry($first)['next_due']);

        // Five minutes on the definition is still the one read above; the
        // due time is not.
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:05:30', 'UTC'));
        $this->assertSame('2026-09-20 12:06:00+00:00', $retry($health->schedule())['next_due']);
        $this->assertFalse($health->schedule()['last_run_recorded']);
    }

    public function test_support_sees_failed_jobs_but_cannot_touch_them(): void
    {
        $this->stopTheClock();
        $this->signIn($this->staffMember(PlatformRole::Support));

        $job = $this->failedJob('mail');

        Livewire::test(OperationsHealth::class)
            ->assertCanSeeTableRecords([$job])
            ->assertTableActionHidden('retry', $job)
            ->assertTableActionHidden('forget', $job)
            ->assertTableBulkActionHidden('retrySelected')
            ->assertSee('Only administrators may retry or forget them.');

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_operations_counts_what_is_waiting_and_hides_payout_amounts_from_support(): void
    {
        $this->seedTrade();
        $this->failedJob('mail');

        $this->signIn($this->staffMember(PlatformRole::Admin));
        Livewire::test(OperationsHealth::class)
            ->assertSee('Payout requests waiting')
            ->assertSee('1 in CAD ($50.00)');

        $this->signIn($this->staffMember(PlatformRole::Support));
        Livewire::test(OperationsHealth::class)
            ->assertSee('1 in CAD')
            ->assertDontSee('$50.00');
    }

    /** A failed job as the queue worker would have recorded it; returns its uuid. */
    private function failedJob(string $queue): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Mail\\YourTicket',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'App\\Mail\\YourTicket', 'command' => 'O:8:"stdClass":0:{}'],
                'attempts' => 3,
            ]),
            'exception' => "RuntimeException: The mail server said no\n#0 stack trace",
            'failed_at' => now()->subHour(),
        ]);

        return $uuid;
    }
}
