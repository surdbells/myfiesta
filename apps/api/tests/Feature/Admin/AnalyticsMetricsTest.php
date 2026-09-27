<?php

namespace Tests\Feature\Admin;

use App\Services\Analytics\EventMetrics;
use App\Services\Analytics\Market;
use App\Services\Analytics\OrganizerMetrics;
use App\Services\Analytics\Period;
use App\Services\Analytics\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The figures behind the admin's performance screens, against a week of
 * trade whose every number was worked out by hand — see AnalyticsFixtures.
 */
class AnalyticsMetricsTest extends TestCase
{
    use AnalyticsFixtures, RefreshDatabase;

    private function week(string $currency = 'CAD'): Period
    {
        return Period::resolve('7d', timezone: Market::timezone($currency), now: $this->now);
    }

    public function test_a_period_is_whole_days_on_the_markets_own_clock(): void
    {
        $this->stopTheClock();

        $period = $this->week();

        $this->assertSame('2026-09-14 04:00:00+00:00', $period->fromSql());
        $this->assertSame('2026-09-21 04:00:00+00:00', $period->untilSql());
        $this->assertSame(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'], $period->buckets());
        $this->assertSame('2026-09-07', $period->previous()->from->toDateString());

        $lagos = $this->week('NGN');
        $this->assertSame('2026-09-13 23:00:00+00:00', $lagos->fromSql());

        $this->assertSame('week', Period::resolve('90d', now: $this->now)->granularity());
        $this->assertSame('month', Period::resolve('12m', now: $this->now)->granularity());

        $custom = Period::resolve('custom', '2026-09-30', '2026-09-01', 'UTC', $this->now);
        $this->assertSame(30, $custom->days(), 'A range given backwards is read forwards, both ends included.');
        $this->assertSame('30d', Period::resolve('custom', 'not a date', '2026-09-01', 'UTC', $this->now)->key);
    }

    public function test_the_platform_overview_counts_each_sale_once_in_its_own_currency(): void
    {
        $this->seedTrade();

        $overview = app(PlatformMetrics::class)->overview('CAD', $this->week());
        $now = $overview['current'];

        $this->assertSame(5, $now['orders'], 'O1, O2, O3, O4 and O7. The pending order was never a sale; O6 was last week.');
        $this->assertSame(6, $now['tickets']);
        $this->assertSame(39270, $now['gross']);
        $this->assertSame(34000, $now['net']);
        $this->assertSame(3770, $now['tax']);
        $this->assertSame(1000, $now['discount']);
        $this->assertSame(1500, $now['service']);
        $this->assertSame(950, $now['fees']);
        $this->assertSame(1, $now['fees_pending'], 'O7 has a processor and no fee yet.');
        $this->assertSame(1, $now['refunds']);
        $this->assertSame(5900, $now['refunded']);
        $this->assertSame(250, $now['refunded_service']);
        $this->assertSame(300, $now['net_take'], '1500 service less 950 fees less 250 service handed back.');
        $this->assertEqualsWithDelta(5900 / 39270, $now['refund_rate'], 1e-9);
        $this->assertSame(1, $now['door_orders']);
        $this->assertSame(4, $now['online_orders']);
        $this->assertSame(1, $now['organizations']);
        $this->assertSame(7854, $now['average_order']);

        $this->assertSame(1, $overview['previous']['orders']);
        $this->assertSame(5900, $overview['previous']['gross']);

        $this->assertSame(['open' => 1, 'open_amount' => 10670, 'opened' => 1], $overview['disputes']);
        $this->assertSame(['count' => 1, 'amount' => 5000], $overview['payout_requests']);
        $this->assertSame(['owed' => 19000, 'organizations' => 1, 'overdrawn' => 0], $overview['owed']);
        $this->assertSame(1, $overview['events_on_sale'], 'Winter Warmer. The Harvest Moon night has been held.');
        $this->assertSame(['current' => 1, 'previous' => 0], $overview['new_organizers']);
    }

    public function test_naira_and_dollars_are_never_added_together(): void
    {
        $this->seedTrade();

        $naira = app(PlatformMetrics::class)->overview('NGN', $this->week('NGN'))['current'];

        $this->assertSame(1, $naira['orders']);
        $this->assertSame(1_125_000, $naira['gross']);
        $this->assertSame(4, $naira['tickets']);
        $this->assertSame(20_000, $naira['fees']);

        $dollars = app(PlatformMetrics::class)->overview('CAD', $this->week())['current'];
        $this->assertSame(39270, $dollars['gross'], 'The naira order is not in the dollar figure.');
    }

    public function test_the_trend_puts_each_sale_on_the_day_it_happened_in_toronto(): void
    {
        $this->seedTrade();

        $trend = app(PlatformMetrics::class)->trend('CAD', $this->week());

        // O2 was paid at 01:00 UTC on the 18th: 9pm on the 17th in Toronto.
        $this->assertSame([0, 11800, 5900, 10670, 0, 10900, 0], $trend['gross']);
        $this->assertSame([0, 1, 1, 1, 0, 2, 0], $trend['orders']);
        $this->assertSame([0, 2, 1, 1, 0, 2, 0], $trend['tickets']);
        $this->assertSame([0, 0, 0, 5900, 0, 0, 0], $trend['refunded']);
        $this->assertSame(['Sep 14', 'Sep 15', 'Sep 16', 'Sep 17', 'Sep 18', 'Sep 19', 'Sep 20'], $trend['labels']);
        $this->assertSame(300, array_sum($trend['net_take']), 'The buckets add up to the headline figure.');
    }

    public function test_the_revenue_split_adds_up_to_the_gross(): void
    {
        $this->seedTrade();

        $split = app(PlatformMetrics::class)->revenueSplit('CAD', $this->week());

        $this->assertSame(['gross' => 39270, 'organizer' => 29000, 'platform' => 1250, 'tax' => 3120, 'refunded' => 5900], $split);
        $this->assertSame($split['gross'], $split['organizer'] + $split['platform'] + $split['tax'] + $split['refunded']);

        // Day by day, refunds off the day the order was sold: O4 (Sep 16)
        // was refunded in full on the 17th, so the 16th kept nothing and the
        // 17th keeps all of O2.
        $kept = app(PlatformMetrics::class)->revenueSplitTrend('CAD', $this->week());

        $this->assertSame([0, 10000, 0, 9000, 0, 10000, 0], $kept['organizer']);
        $this->assertSame([0, 500, 0, 500, 0, 250, 0], $kept['platform']);
        $this->assertSame([0, 1300, 0, 1170, 0, 650, 0], $kept['tax']);
        $this->assertSame($split['organizer'], array_sum($kept['organizer']));
        $this->assertSame($split['platform'], array_sum($kept['platform']));
        $this->assertSame($split['tax'], array_sum($kept['tax']));
    }

    public function test_rankings_channels_places_and_check_ins(): void
    {
        $this->seedTrade();
        $metrics = app(PlatformMetrics::class);
        $week = $this->week();

        $this->assertSame([['id' => $this->toronto->id, 'name' => 'Toronto Collective', 'orders' => 5, 'gross' => 39270]], $metrics->topOrganizers('CAD', $week));
        $this->assertSame('Harvest Moon Party', $metrics->topEvents('CAD', $week)[0]['title']);
        $this->assertSame(39270, $metrics->topEvents('CAD', $week)[0]['gross']);

        $this->assertSame([
            'online' => ['orders' => 4, 'gross' => 34270, 'tickets' => 5],
            'door' => ['orders' => 1, 'gross' => 5000, 'tickets' => 1],
        ], $metrics->byChannel('CAD', $week));

        $this->assertSame([['city' => 'Toronto', 'country' => 'CA', 'orders' => 5, 'gross' => 39270]], $metrics->byCity('CAD', $week));
        $this->assertSame([['country' => 'CA', 'orders' => 5, 'gross' => 39270]], $metrics->byCountry('CAD', $week));

        $checkIns = $metrics->checkIns('CAD', $week);
        $this->assertSame(9, $checkIns['people'], 'Six live tickets, one of them a table for four.');
        $this->assertSame(6, $checkIns['arrived']);
        $this->assertEqualsWithDelta(6 / 9, $checkIns['overall'], 1e-9);
        $this->assertCount(1, $checkIns['events'], 'The upcoming night has not been held.');
    }

    public function test_one_organizers_report(): void
    {
        $this->seedTrade();

        $m = app(OrganizerMetrics::class)->for($this->toronto->id, 'CAD', $this->week());

        $this->assertSame(39270, $m['current']['gross']);
        $this->assertSame(5900, $m['previous']['gross']);
        $this->assertSame([0, 11800, 5900, 10670, 0, 10900, 0], $m['series']['gross']);

        $this->assertSame(['opened' => 1, 'open' => 1, 'rate' => 0.2], $m['disputes']);

        $this->assertSame(19000, $m['payouts']['owed']);
        $this->assertSame(10000, $m['payouts']['paid']);
        $this->assertSame(1, $m['payouts']['settlements']);
        $this->assertSame(5000, $m['payouts']['pending_request']);
        $this->assertSame([0, 10000, 5000, 9000, 0, 10000, 0], $m['payouts']['earned_series']);
        $this->assertSame([0, 0, 0, 0, 10000, 0, 0], $m['payouts']['paid_series']);

        $this->assertSame(['views' => 100, 'online_orders' => 4, 'rate' => 0.04], $m['conversion'], 'Only the views inside the week.');

        // ada@ bought twice (once as Ada@Example.com); bola@ and dayo@ once.
        // chi@ bought last week only, so is not a buyer in this one.
        $this->assertSame(3, $m['buyers']['buyers']);
        $this->assertSame(1, $m['buyers']['repeat']);

        $this->assertSame([[
            'id' => $this->night->id,
            'title' => 'Harvest Moon Party',
            'starts_at' => $m['fill'][0]['starts_at'],
            'sold' => 5,
            'comps' => 1,
            'capacity' => 120,
        ]], $m['fill']);

        $this->assertSame(1, count($m['events']));
        $this->assertSame(['gross' => 39270, 'organizer' => 29000, 'platform' => 1250, 'tax' => 3120, 'refunded' => 5900], $m['split']);
    }

    public function test_the_organizer_table_sorts_and_filters_on_its_measures_in_the_database(): void
    {
        $this->seedTrade();

        $rows = app(OrganizerMetrics::class)->table('CAD', $this->week())->orderByDesc('gross')->get()->keyBy('name');

        $toronto = $rows['Toronto Collective'];
        $this->assertSame(39270, (int) $toronto->gross);
        $this->assertSame(5, (int) $toronto->orders_count);
        $this->assertSame(6, (int) $toronto->tickets_sold);
        $this->assertSame(5900, (int) $toronto->refunded);
        $this->assertSame(1, (int) $toronto->open_disputes);
        $this->assertSame(19000, (int) $toronto->owed);
        $this->assertSame(10000, (int) $toronto->paid_out);
        $this->assertSame(1, (int) $toronto->events_in_period);
        $this->assertEqualsWithDelta(5900 / 39270, (float) $toronto->refund_rate, 1e-9);

        $lagos = $rows['Lagos Nights'];
        $this->assertSame(0, (int) $lagos->gross, 'Its naira sale is not a dollar sale.');
        $this->assertNull($lagos->refund_rate);

        $this->assertSame(['Toronto Collective'], app(OrganizerMetrics::class)->table('CAD', $this->week())
            ->where('orders_count', '>', 0)->pluck('name')->all());
        $this->assertSame(1, app(OrganizerMetrics::class)->table('CAD', $this->week())->where('owed', '>', 0)->count());
    }

    public function test_one_events_report_over_its_whole_life(): void
    {
        $this->seedTrade();

        $m = app(EventMetrics::class)->for($this->night);
        $t = $m['totals'];

        $this->assertSame(6, $t['orders'], 'Every sale, last week\'s included.');
        $this->assertSame(45170, $t['gross']);
        $this->assertSame(7, $t['tickets']);
        $this->assertSame(1150, $t['fees']);
        $this->assertSame(5900, $t['refunded']);
        $this->assertSame(['gross' => 45170, 'organizer' => 34000, 'platform' => 1500, 'tax' => 3770, 'refunded' => 5900], $m['split']);

        $this->assertSame(['sold' => 5, 'comps' => 1, 'people' => 9, 'arrived' => 6, 'capacity' => 120, 'rate' => 6 / 9], $m['attendance']);
        $this->assertSame(150, $m['views']);

        // Cumulative tickets, counted back from the night: O6 nine days out,
        // O1 four, O4 three, O2 two, and O7 and the door sale on the night.
        $this->assertSame(
            [[9, 1], [8, 1], [7, 1], [6, 1], [5, 1], [4, 3], [3, 4], [2, 5], [1, 5], [0, 7]],
            array_map(fn (array $p) => [$p['days_before'], $p['tickets']], $m['pace']),
        );

        $this->assertSame([
            ['name' => 'GA', 'tickets' => 6, 'revenue' => 30000, 'capacity' => 100],
            ['name' => 'VIP table', 'tickets' => 1, 'revenue' => 9000, 'capacity' => 20],
        ], array_map(fn (array $type) => ['name' => $type['name'], 'tickets' => $type['tickets'], 'revenue' => $type['revenue'], 'capacity' => $type['capacity']], $m['ticket_types']));

        $this->assertSame(1, $m['codes']['orders']);
        $this->assertSame(['code' => 'EARLY', 'label' => null, 'orders' => 1, 'discount' => 1000, 'gross' => 10670, 'removed' => false], $m['codes']['codes'][0]);

        // On Toronto's clock, fifteen minutes at a time; the quiet 22:45 slot
        // is a zero rather than a gap, and the duplicate is turned away.
        $this->assertSame(['22:00', '22:15', '22:30', '22:45', '23:00'], $m['check_ins']['labels']);
        $this->assertSame([1, 3, 1, 0, 1], $m['check_ins']['people']);
        $this->assertSame(6, $m['check_ins']['total']);
        $this->assertSame(1, $m['check_ins']['turned_away']);

        $this->assertSame([
            'online' => ['orders' => 5, 'gross' => 40170, 'tickets' => 6],
            'door' => ['orders' => 1, 'gross' => 5000, 'tickets' => 1],
        ], $m['channels']);

        $this->assertCount(1, $m['refunds']);
        $this->assertSame($this->orders['O4']->reference, $m['refunds'][0]['reference']);
    }

    public function test_the_event_table_carries_each_events_measures_and_rates(): void
    {
        $this->seedTrade();

        $rows = app(EventMetrics::class)->table()->get()->keyBy('title');
        $night = $rows['Harvest Moon Party'];

        $this->assertSame(45170, (int) $night->gross);
        $this->assertSame(6, (int) $night->orders_count);
        $this->assertSame(5, (int) $night->online_orders);
        $this->assertSame(1, (int) $night->door_orders);
        $this->assertSame(7, (int) $night->tickets_sold);
        $this->assertSame(120, (int) $night->capacity);
        $this->assertSame(150, (int) $night->views);
        $this->assertEqualsWithDelta(5 / 120, (float) $night->fill_rate, 1e-9);
        $this->assertEqualsWithDelta(6 / 9, (float) $night->check_in_rate, 1e-9);
        $this->assertEqualsWithDelta(5 / 150, (float) $night->conversion, 1e-9);

        $this->assertSame(1_125_000, (int) $rows['Eko Groove']->gross);
        $this->assertSame('NGN', $rows['Eko Groove']->currency);

        $this->assertSame(['Harvest Moon Party'], app(EventMetrics::class)->table()->orderByRaw('check_in_rate desc nulls last')->limit(1)->pluck('title')->all());
    }
}
