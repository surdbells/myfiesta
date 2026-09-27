<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * How the whole platform is doing, one market at a time.
 *
 * What the operator's dashboard reads. Every figure is for one currency and
 * one period, compared with the period before where a comparison means
 * something, and a snapshot — "disputes open", "owed to organizers" — where
 * it is a state rather than a flow.
 */
class PlatformMetrics extends Metrics
{
    /**
     * The KPI row.
     *
     * @return array<string, mixed>
     */
    public function overview(string $currency, Period $period): array
    {
        return $this->remember('platform.overview', [$currency, $period->cacheKey()], function () use ($currency, $period) {
            $previous = $period->previous();

            return [
                'current' => $this->totals($currency, $period),
                'previous' => $this->totals($currency, $previous),
                'disputes' => $this->disputes($currency, $period),
                'payout_requests' => $this->pendingPayoutRequests($currency),
                'owed' => $this->owedToOrganizers($currency),
                'events_on_sale' => $this->eventsOnSale($currency),
                'new_organizers' => [
                    'current' => $this->newOrganizers($period),
                    'previous' => $this->newOrganizers($previous),
                ],
            ];
        });
    }

    /** @return array<string, list<int|string>> */
    public function trend(string $currency, Period $period): array
    {
        return $this->remember('platform.trend', [$currency, $period->cacheKey()], fn () => $this->series($currency, $period));
    }

    /** @return array{gross: int, organizer: int, platform: int, tax: int, refunded: int} */
    public function revenueSplit(string $currency, Period $period): array
    {
        return $this->remember('platform.split', [$currency, $period->cacheKey()], fn () => $this->split($currency, $period));
    }

    /**
     * The split's kept parts per bucket, after refunds, adding up to it.
     *
     * @return array{organizer: list<int>, platform: list<int>, tax: list<int>}
     */
    public function revenueSplitTrend(string $currency, Period $period): array
    {
        return $this->remember('platform.split-trend', [$currency, $period->cacheKey()], fn () => $this->splitSeries($currency, $period));
    }

    /**
     * Organizers by what they sold in the period.
     *
     * @return list<array{id: string, name: string, orders: int, gross: int}>
     */
    public function topOrganizers(string $currency, Period $period, int $limit = 10): array
    {
        return $this->remember('platform.top-organizers', [$currency, $period->cacheKey(), $limit], fn () => $this->sold($currency, $period)
            ->join('organizations', 'organizations.id', '=', 'orders.organization_id')
            ->groupBy('orders.organization_id', 'organizations.name')
            ->selectRaw('orders.organization_id as id, organizations.name as name')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->orderByDesc('gross')
            ->orderBy('organizations.name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'orders' => (int) $row->orders,
                'gross' => (int) $row->gross,
            ])
            ->all());
    }

    /**
     * Events by what they sold in the period.
     *
     * @return list<array{id: string, title: string, organization: string, starts_at: string, orders: int, gross: int}>
     */
    public function topEvents(string $currency, Period $period, int $limit = 10): array
    {
        return $this->remember('platform.top-events', [$currency, $period->cacheKey(), $limit], fn () => $this->sold($currency, $period)
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->join('organizations', 'organizations.id', '=', 'orders.organization_id')
            ->groupBy('orders.event_id', 'events.title', 'events.starts_at', 'organizations.name')
            ->selectRaw('orders.event_id as id, events.title as title, events.starts_at as starts_at, organizations.name as organization')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->orderByDesc('gross')
            ->orderBy('events.title')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'organization' => (string) $row->organization,
                'starts_at' => (string) $row->starts_at,
                'orders' => (int) $row->orders,
                'gross' => (int) $row->gross,
            ])
            ->all());
    }

    /**
     * Online against the door.
     *
     * @return array<string, array{orders: int, gross: int, tickets: int}>
     */
    public function byChannel(string $currency, Period $period): array
    {
        return $this->remember('platform.channels', [$currency, $period->cacheKey()], function () use ($currency, $period) {
            $orders = $this->sold($currency, $period)
                ->groupBy('orders.channel')
                ->selectRaw('orders.channel as channel, count(*) as orders, coalesce(sum(orders.total_amount), 0) as gross')
                ->get()
                ->keyBy('channel');

            $tickets = $this->sold($currency, $period)
                ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
                ->whereNotNull('order_lines.ticket_type_id')
                ->groupBy('orders.channel')
                ->selectRaw('orders.channel as channel, coalesce(sum(order_lines.quantity), 0) as tickets')
                ->pluck('tickets', 'channel');

            $out = [];

            foreach (['online', 'door'] as $channel) {
                $out[$channel] = [
                    'orders' => (int) ($orders[$channel]->orders ?? 0),
                    'gross' => (int) ($orders[$channel]->gross ?? 0),
                    'tickets' => (int) ($tickets[$channel] ?? 0),
                ];
            }

            return $out;
        });
    }

    /**
     * Where the nights were, by city.
     *
     * The top few cities by gross; the rest fold into one "Other" row rather
     * than stretching the chart into a list nobody reads.
     *
     * @return list<array{city: string, country: string, orders: int, gross: int, other?: bool}>
     */
    public function byCity(string $currency, Period $period, int $limit = 8): array
    {
        return $this->remember('platform.cities', [$currency, $period->cacheKey(), $limit], function () use ($currency, $period, $limit) {
            $rows = $this->sold($currency, $period)
                ->join('events', 'events.id', '=', 'orders.event_id')
                ->groupBy('events.country', 'events.city')
                ->selectRaw('events.city as city, events.country as country')
                ->selectRaw('count(*) as orders')
                ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
                ->orderByDesc('gross')
                ->orderBy('events.city')
                ->get()
                ->map(fn ($row) => [
                    'city' => (string) $row->city,
                    'country' => (string) $row->country,
                    'orders' => (int) $row->orders,
                    'gross' => (int) $row->gross,
                ]);

            if ($rows->count() <= $limit) {
                return $rows->values()->all();
            }

            $rest = $rows->slice($limit - 1);

            return $rows->take($limit - 1)
                ->push([
                    'city' => $rest->count().' other cities',
                    'country' => '',
                    'orders' => (int) $rest->sum('orders'),
                    'gross' => (int) $rest->sum('gross'),
                    'other' => true,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * The same sales by the country the night was in.
     *
     * A market is a currency, not a country: a naira night can be held in
     * Accra and a dollar one across the border, and this is where that shows.
     *
     * @return list<array{country: string, orders: int, gross: int}>
     */
    public function byCountry(string $currency, Period $period): array
    {
        return $this->remember('platform.countries', [$currency, $period->cacheKey()], fn () => $this->sold($currency, $period)
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->groupBy('events.country')
            ->selectRaw('events.country as country')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->orderByDesc('gross')
            ->orderBy('events.country')
            ->get()
            ->map(fn ($row) => [
                'country' => (string) $row->country,
                'orders' => (int) $row->orders,
                'gross' => (int) $row->gross,
            ])
            ->all());
    }

    /**
     * How many of the people holding tickets came, for nights already held.
     *
     * Measured in people, not tickets: a table for five is one ticket and
     * five arrivals. Comps count — somebody on the list who did not come is
     * as much a no-show as somebody who paid.
     *
     * @return array{overall: ?float, people: int, arrived: int, events: list<array{id: string, title: string, starts_at: string, people: int, arrived: int, rate: ?float}>}
     */
    public function checkIns(string $currency, Period $period, int $limit = 10): array
    {
        return $this->remember('platform.check-ins', [$currency, $period->cacheKey(), $limit], function () use ($currency, $period, $limit) {
            $rows = DB::table('events')
                ->join('tickets', 'tickets.event_id', '=', 'events.id')
                ->where('events.currency', $currency)
                ->where('events.status', 'published')
                ->where('events.kind', 'ticketed')
                ->whereNull('events.deleted_at')
                ->whereIn('tickets.status', self::LIVE_TICKETS)
                ->whereRaw('events.starts_at >= ?::timestamptz', [$period->fromSql()])
                ->whereRaw('events.starts_at < least(?::timestamptz, now())', [$period->untilSql()])
                ->groupBy('events.id', 'events.title', 'events.starts_at')
                ->selectRaw('events.id as id, events.title as title, events.starts_at as starts_at')
                ->selectRaw('coalesce(sum(tickets.admits), 0) as people')
                ->selectRaw('coalesce(sum(tickets.admitted_count), 0) as arrived')
                ->orderByDesc('events.starts_at')
                ->get();

            $people = (int) $rows->sum('people');
            $arrived = (int) $rows->sum('arrived');

            return [
                'overall' => self::ratio($arrived, $people),
                'people' => $people,
                'arrived' => $arrived,
                'events' => $rows->take($limit)->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'title' => (string) $row->title,
                    'starts_at' => (string) $row->starts_at,
                    'people' => (int) $row->people,
                    'arrived' => (int) $row->arrived,
                    'rate' => self::ratio((int) $row->arrived, (int) $row->people),
                ])->values()->all(),
            ];
        });
    }

    /** @return array{open: int, open_amount: int, opened: int} */
    private function disputes(string $currency, Period $period): array
    {
        $row = DB::table('disputes')
            ->where('currency', $currency)
            ->selectRaw("count(*) filter (where status = 'open') as open")
            ->selectRaw("coalesce(sum(amount) filter (where status = 'open'), 0) as open_amount")
            ->selectRaw('count(*) filter (where opened_at >= ?::timestamptz and opened_at < ?::timestamptz) as opened', [$period->fromSql(), $period->untilSql()])
            ->first();

        return [
            'open' => (int) $row->open,
            'open_amount' => (int) $row->open_amount,
            'opened' => (int) $row->opened,
        ];
    }

    /** @return array{count: int, amount: int} */
    private function pendingPayoutRequests(string $currency): array
    {
        $row = DB::table('payout_requests')
            ->where('currency', $currency)
            ->where('status', 'pending')
            ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as amount')
            ->first();

        return ['count' => (int) $row->count, 'amount' => (int) $row->amount];
    }

    /**
     * What the platform holds for organizers, now.
     *
     * The sum of positive balances only: an organization paid ahead of its
     * sales owes the platform, and letting that cancel out somebody else's
     * balance would understate what has to be paid out. Overdrafts are
     * reported beside it instead.
     *
     * @return array{owed: int, organizations: int, overdrawn: int}
     */
    private function owedToOrganizers(string $currency): array
    {
        $balances = DB::table('ledger_entries')
            ->where('currency', $currency)
            ->groupBy('organization_id')
            ->selectRaw('organization_id, sum(amount) as balance');

        $row = DB::query()
            ->fromSub($balances, 'b')
            ->selectRaw('coalesce(sum(balance) filter (where balance > 0), 0) as owed')
            ->selectRaw('count(*) filter (where balance > 0) as organizations')
            ->selectRaw('coalesce(-sum(balance) filter (where balance < 0), 0) as overdrawn')
            ->first();

        return [
            'owed' => (int) $row->owed,
            'organizations' => (int) $row->organizations,
            'overdrawn' => (int) $row->overdrawn,
        ];
    }

    /**
     * Published, ticketed nights still to come with something on sale.
     */
    private function eventsOnSale(string $currency): int
    {
        return DB::table('events')
            ->where('events.currency', $currency)
            ->where('events.status', 'published')
            ->where('events.kind', 'ticketed')
            ->whereNull('events.deleted_at')
            ->whereRaw('coalesce(events.ends_at, events.starts_at) >= now()')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('ticket_types')
                ->whereColumn('ticket_types.event_id', 'events.id')
                ->whereNull('ticket_types.deleted_at')
                ->where('ticket_types.status', 'on_sale'))
            ->count();
    }

    /** Organizations that signed up in the period. Not per currency: an organization has none. */
    private function newOrganizers(Period $period): int
    {
        return DB::table('organizations')
            ->whereNull('deleted_at')
            ->whereRaw('created_at >= ?::timestamptz', [$period->fromSql()])
            ->whereRaw('created_at < ?::timestamptz', [$period->untilSql()])
            ->count();
    }
}
