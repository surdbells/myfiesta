<?php

namespace App\Services\Analytics;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How one organizer is doing, and how every organizer compares.
 *
 * The single-organizer report answers what an account manager is asked:
 * how much did they sell and on which nights, are their rooms filling, how
 * often does money go back, what are they owed and what have they been paid,
 * do people who look at their pages buy, and do buyers come back.
 *
 * The table is the same measures for every organization at once, as SQL
 * subqueries on each row, so it sorts and pages in the database like any
 * other listing.
 */
class OrganizerMetrics extends Metrics
{
    /** @return array<string, mixed> */
    public function for(string $organizationId, string $currency, Period $period): array
    {
        return $this->remember('organizer', [$organizationId, $currency, $period->cacheKey()], function () use ($organizationId, $currency, $period) {
            $scope = ['organization_id' => $organizationId];
            $current = $this->totals($currency, $period, $scope);

            return [
                'current' => $current,
                'previous' => $this->totals($currency, $period->previous(), $scope),
                'series' => $this->series($currency, $period, $scope),
                'split' => $this->split($currency, $period, $scope),
                'events' => $this->eventsRanked($organizationId, $currency, $period),
                'fill' => $this->fill($organizationId, $currency, $period),
                'payouts' => $this->payouts($organizationId, $currency, $period),
                'disputes' => $this->disputes($organizationId, $currency, $period, $current['orders']),
                'conversion' => $this->conversion($organizationId, $currency, $period),
                'buyers' => $this->buyers($organizationId, $currency, $period),
            ];
        });
    }

    /**
     * Their events by what each sold in the period.
     *
     * @return list<array{id: string, title: string, starts_at: string, orders: int, gross: int}>
     */
    private function eventsRanked(string $organizationId, string $currency, Period $period, int $limit = 10): array
    {
        return $this->sold($currency, $period, ['organization_id' => $organizationId])
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->groupBy('orders.event_id', 'events.title', 'events.starts_at')
            ->selectRaw('orders.event_id as id, events.title as title, events.starts_at as starts_at')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->orderByDesc('gross')
            ->orderBy('events.title')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'starts_at' => (string) $row->starts_at,
                'orders' => (int) $row->orders,
                'gross' => (int) $row->gross,
            ])
            ->all();
    }

    /**
     * How full their nights in the period are: tickets sold that still admit
     * somebody, against what the ticket types allow.
     *
     * Capacity is null when any tier is unlimited — a room with no ceiling
     * has no percentage full.
     *
     * @return list<array{id: string, title: string, starts_at: string, sold: int, comps: int, capacity: ?int}>
     */
    private function fill(string $organizationId, string $currency, Period $period, int $limit = 10): array
    {
        return DB::table('events')
            ->where('events.organization_id', $organizationId)
            ->where('events.currency', $currency)
            ->whereNull('events.deleted_at')
            // Nights that have been on sale. One waiting for review has not
            // yet, any more than a draft has.
            ->whereNotIn('events.status', ['draft', 'in_review'])
            ->where('events.kind', 'ticketed')
            ->whereRaw('events.starts_at >= ?::timestamptz', [$period->fromSql()])
            ->whereRaw('events.starts_at < ?::timestamptz', [$period->untilSql()])
            ->select('events.id', 'events.title', 'events.starts_at')
            ->selectSub(self::liveTickets()->whereNotNull('tickets.order_id')->selectRaw('count(*)'), 'sold')
            ->selectSub(self::liveTickets()->whereNull('tickets.order_id')->selectRaw('count(*)'), 'comps')
            ->selectSub(self::capacity(), 'capacity')
            ->orderByDesc('events.starts_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'starts_at' => (string) $row->starts_at,
                'sold' => (int) $row->sold,
                'comps' => (int) $row->comps,
                'capacity' => $row->capacity === null ? null : (int) $row->capacity,
            ])
            ->all();
    }

    /**
     * What they are owed now, and what they were paid in the period.
     *
     * The series sets what they earned — their net on each bucket's sales —
     * beside what was paid out in it. Owed is the ledger balance, the same
     * figure the settle and payout screens use.
     *
     * @return array{owed: int, paid: int, settlements: int, pending_request: ?int, earned_series: list<int>, paid_series: list<int>}
     */
    private function payouts(string $organizationId, string $currency, Period $period): array
    {
        $owed = (int) DB::table('ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->sum('amount');

        $paid = $this->settlements($organizationId, $currency, $period);

        $bucket = "to_char(date_trunc('".$period->granularity()."', coalesce(settlements.settled_at, settlements.created_at) at time zone ?)::date, 'YYYY-MM-DD')";

        $byBucket = $paid->clone()
            ->selectRaw("{$bucket} as bucket", [$period->timezone])
            ->selectRaw('coalesce(sum(settlements.amount), 0) as amount')
            ->groupByRaw('1')
            ->pluck('amount', 'bucket');

        $totals = $paid->clone()->selectRaw('count(*) as settlements, coalesce(sum(settlements.amount), 0) as amount')->first();

        $request = DB::table('payout_requests')
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->where('status', 'pending')
            ->value('amount');

        $earned = $this->series($currency, $period, ['organization_id' => $organizationId])['net'];

        return [
            'owed' => $owed,
            'paid' => (int) $totals->amount,
            'settlements' => (int) $totals->settlements,
            'pending_request' => $request === null ? null : (int) $request,
            'earned_series' => $earned,
            'paid_series' => array_map(fn (string $b) => (int) ($byBucket[$b] ?? 0), $period->buckets()),
        ];
    }

    private function settlements(string $organizationId, string $currency, Period $period): Builder
    {
        return DB::table('settlements')
            ->where('settlements.organization_id', $organizationId)
            ->where('settlements.currency', $currency)
            ->where('settlements.status', 'success')
            ->whereRaw('coalesce(settlements.settled_at, settlements.created_at) >= ?::timestamptz', [$period->fromSql()])
            ->whereRaw('coalesce(settlements.settled_at, settlements.created_at) < ?::timestamptz', [$period->untilSql()]);
    }

    /** @return array{opened: int, open: int, rate: ?float} */
    private function disputes(string $organizationId, string $currency, Period $period, int $orders): array
    {
        $row = DB::table('disputes')
            ->where('organization_id', $organizationId)
            ->where('currency', $currency)
            ->selectRaw("count(*) filter (where status = 'open') as open")
            ->selectRaw('count(*) filter (where opened_at >= ?::timestamptz and opened_at < ?::timestamptz) as opened', [$period->fromSql(), $period->untilSql()])
            ->first();

        return [
            'opened' => (int) $row->opened,
            'open' => (int) $row->open,
            'rate' => self::ratio((int) $row->opened, $orders),
        ];
    }

    /**
     * Page views against online orders, in the period.
     *
     * Views are a count per event per day and nothing more, and were not
     * recorded before September 2026 — with none to divide by, there is no
     * rate, not a zero one. Door sales never saw a page and are left out.
     *
     * @return array{views: int, online_orders: int, rate: ?float}
     */
    private function conversion(string $organizationId, string $currency, Period $period): array
    {
        $views = (int) DB::table('event_views')
            ->join('events', 'events.id', '=', 'event_views.event_id')
            ->where('events.organization_id', $organizationId)
            ->where('events.currency', $currency)
            ->where('event_views.day', '>=', $period->from->toDateString())
            ->where('event_views.day', '<', $period->until->toDateString())
            ->sum(DB::raw('event_views.views + event_views.embed_views'));

        $online = $this->sold($currency, $period, ['organization_id' => $organizationId])
            ->where('orders.channel', 'online')
            ->count();

        return [
            'views' => $views,
            'online_orders' => $online,
            'rate' => self::ratio($online, $views),
        ];
    }

    /**
     * Of the people who bought in the period, how many had bought from this
     * organizer before, or bought twice in it.
     *
     * Counted by email address — the only identity a guest checkout has.
     * Door sales without one are not anybody who can come back.
     *
     * @return array{buyers: int, repeat: int, rate: ?float}
     */
    private function buyers(string $organizationId, string $currency, Period $period): array
    {
        $perBuyer = DB::table('orders')
            ->where('orders.organization_id', $organizationId)
            ->where('orders.currency', $currency)
            ->whereRaw(self::SOLD_SQL)
            ->whereNotNull('orders.buyer_email')
            ->whereRaw(self::SOLD_AT.' < ?::timestamptz', [$period->untilSql()])
            ->groupByRaw('lower(orders.buyer_email)')
            ->selectRaw('count(*) as orders')
            ->selectRaw('bool_or('.self::SOLD_AT.' >= ?::timestamptz) as in_period', [$period->fromSql()]);

        $row = DB::query()
            ->fromSub($perBuyer, 'b')
            ->whereRaw('b.in_period')
            ->selectRaw('count(*) as buyers')
            ->selectRaw('count(*) filter (where orders >= 2) as repeat')
            ->first();

        return [
            'buyers' => (int) $row->buyers,
            'repeat' => (int) $row->repeat,
            'rate' => self::ratio((int) $row->repeat, (int) $row->buyers),
        ];
    }

    /**
     * Every organization with its figures for one currency and period.
     *
     * Each measure is a correlated subquery on the organization's own rows,
     * reached through the organization_id indexes. The whole is then wrapped
     * as a derived table named "organizations", so every measure is an
     * ordinary column to the listing: it can be sorted, filtered on and
     * divided — refund rate — and paged, all in the database.
     */
    public function table(string $currency, Period $period): EloquentBuilder
    {
        return Organization::query()
            ->fromSub($this->measures($currency, $period), 'organizations')
            ->select('organizations.*')
            ->selectRaw('case when organizations.gross > 0 then organizations.refunded::numeric / organizations.gross end as refund_rate');
    }

    private function measures(string $currency, Period $period): EloquentBuilder
    {
        $sold = fn () => $this->sold($currency, $period)->whereColumn('orders.organization_id', 'organizations.id');

        // Closed organizations included here and left out by the outer
        // query's own soft-delete scope, so a listing can still ask for them.
        return Organization::withTrashed()
            ->select('organizations.*')
            ->selectSub($sold()->selectRaw('coalesce(sum(orders.total_amount), 0)'), 'gross')
            ->selectSub($sold()->selectRaw('count(*)'), 'orders_count')
            ->selectSub($sold()
                ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
                ->whereNotNull('order_lines.ticket_type_id')
                ->selectRaw('coalesce(sum(order_lines.quantity), 0)'), 'tickets_sold')
            ->selectSub($this->refunds($currency, $period)
                ->whereColumn('refunds.organization_id', 'organizations.id')
                ->selectRaw('coalesce(sum(refunds.amount), 0)'), 'refunded')
            ->selectSub(DB::table('disputes')
                ->whereColumn('disputes.organization_id', 'organizations.id')
                ->where('disputes.currency', $currency)
                ->where('disputes.status', 'open')
                ->selectRaw('count(*)'), 'open_disputes')
            ->selectSub(DB::table('ledger_entries')
                ->whereColumn('ledger_entries.organization_id', 'organizations.id')
                ->where('ledger_entries.currency', $currency)
                ->selectRaw('coalesce(sum(ledger_entries.amount), 0)'), 'owed')
            ->selectSub(DB::table('settlements')
                ->whereColumn('settlements.organization_id', 'organizations.id')
                ->where('settlements.currency', $currency)
                ->where('settlements.status', 'success')
                ->whereRaw('coalesce(settlements.settled_at, settlements.created_at) >= ?::timestamptz', [$period->fromSql()])
                ->whereRaw('coalesce(settlements.settled_at, settlements.created_at) < ?::timestamptz', [$period->untilSql()])
                ->selectRaw('coalesce(sum(settlements.amount), 0)'), 'paid_out')
            ->selectSub(DB::table('events')
                ->whereColumn('events.organization_id', 'organizations.id')
                ->where('events.currency', $currency)
                ->whereNull('events.deleted_at')
                ->whereNotIn('events.status', ['draft', 'in_review'])
                ->whereRaw('events.starts_at >= ?::timestamptz', [$period->fromSql()])
                ->whereRaw('events.starts_at < ?::timestamptz', [$period->untilSql()])
                ->selectRaw('count(*)'), 'events_in_period')
            ->selectSub($sold()->selectRaw('max('.self::SOLD_AT.')'), 'last_sale_at');
    }
}
