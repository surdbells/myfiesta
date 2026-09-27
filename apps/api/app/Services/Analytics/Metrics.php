<?php

namespace App\Services\Analytics;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What every report here counts the same way.
 *
 * A sale is an order that was paid — including one refunded since, because
 * the sale still happened on the day it happened and the refund is reported
 * as its own figure. Gross less refunds is then what was kept, and neither
 * number hides the other. Pending, failed and cancelled orders were never
 * sales at all.
 *
 * A sale happened when it was paid. Orders imported from the old platform
 * were sometimes marked paid without a time, and fall back to when they were
 * created — the same rule the organizer's own sales report uses.
 *
 * Money is summed in one currency at a time, always. Every query here takes a
 * currency and filters on it; nothing adds naira to dollars.
 *
 * Aggregation happens in Postgres. A figure is one query over indexed columns,
 * never a collection summed in PHP, and results are cached briefly per
 * currency and period so a busy dashboard is not the busiest thing on the
 * database.
 */
abstract class Metrics
{
    /** Order statuses that were sales. */
    public const SOLD = ['paid', 'partially_refunded', 'refunded'];

    /**
     * Tickets that still admit somebody. A ticket listed for resale is still
     * its holder's until somebody buys it, and still gets them in.
     */
    public const LIVE_TICKETS = ['valid', 'checked_in', 'listed'];

    /** How long a computed report is reused. Short: a dashboard is read live. */
    public const TTL_SECONDS = 120;

    /**
     * Written as literal SQL rather than bound, so the planner can match the
     * partial index on sold orders — a bound list is opaque to it.
     */
    protected const SOLD_SQL = "orders.status in ('paid', 'partially_refunded', 'refunded')";

    protected const SOLD_AT = 'coalesce(orders.paid_at, orders.created_at)';

    /*
     * The platform's part of a service charge, and all the tax on a sale.
     *
     * A service charge can carry tax inside it (service_charge_tax_amount):
     * HST added to it in Ontario, VAT inside it in Lagos, and always where the
     * platform is the seller of record. That part is owed to a tax authority
     * and was never the platform's to keep, so it is counted as tax, beside
     * the tax on the tickets, and not as platform revenue. Each refund records
     * its own share of it, so what was handed back is split the same way.
     */
    protected const ORDER_SERVICE = '(orders.service_charge_amount - orders.service_charge_tax_amount)';

    protected const ORDER_TAX = '(orders.tax_amount + orders.service_charge_tax_amount)';

    protected const REFUND_SERVICE = '(refunds.service_charge_amount - refunds.service_charge_tax_amount)';

    protected const REFUND_TAX = '(refunds.tax_amount + refunds.service_charge_tax_amount)';

    /**
     * @param  list<scalar|null>  $parts
     */
    protected function remember(string $name, array $parts, Closure $compute): mixed
    {
        return Cache::remember(
            'analytics:v1:'.$name.':'.md5(implode('|', array_map('strval', $parts))),
            static::TTL_SECONDS,
            $compute,
        );
    }

    /**
     * Sales in one currency, within a period when one is given.
     *
     * @param  array{organization_id?: string, event_id?: string}  $scope
     */
    protected function sold(string $currency, ?Period $period = null, array $scope = []): Builder
    {
        return DB::table('orders')
            ->where('orders.currency', $currency)
            ->whereRaw(self::SOLD_SQL)
            ->when($period, fn (Builder $query) => $query
                ->whereRaw(self::SOLD_AT.' >= ?::timestamptz', [$period->fromSql()])
                ->whereRaw(self::SOLD_AT.' < ?::timestamptz', [$period->untilSql()]))
            ->when($scope['organization_id'] ?? null, fn (Builder $query, string $id) => $query->where('orders.organization_id', $id))
            ->when($scope['event_id'] ?? null, fn (Builder $query, string $id) => $query->where('orders.event_id', $id));
    }

    /**
     * Money returned to buyers, dated when the refund was made.
     *
     * @param  array{organization_id?: string, event_id?: string}  $scope
     */
    protected function refunds(string $currency, ?Period $period = null, array $scope = []): Builder
    {
        return DB::table('refunds')
            ->where('refunds.currency', $currency)
            ->where('refunds.status', 'succeeded')
            ->when($period, fn (Builder $query) => $query
                ->whereRaw('refunds.created_at >= ?::timestamptz', [$period->fromSql()])
                ->whereRaw('refunds.created_at < ?::timestamptz', [$period->untilSql()]))
            ->when($scope['organization_id'] ?? null, fn (Builder $query, string $id) => $query->where('refunds.organization_id', $id))
            ->when($scope['event_id'] ?? null, fn (Builder $query, string $id) => $query->where('refunds.event_id', $id));
    }

    /**
     * The headline figures for a slice of sales.
     *
     * Net platform take is what the platform keeps of its service charge:
     * less what the processors took, less the service charge handed back
     * with refunds. Gateway fees are only known once a payment settles, so
     * orders still waiting on theirs are counted rather than guessed. The
     * service charge here is the platform's part, without any tax in it,
     * and that tax is in the tax figure instead (ORDER_SERVICE, ORDER_TAX).
     *
     * @param  array{organization_id?: string, event_id?: string}  $scope
     * @return array<string, int|float|null>
     */
    protected function totals(string $currency, ?Period $period = null, array $scope = []): array
    {
        $orders = $this->sold($currency, $period, $scope)
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->selectRaw('coalesce(sum(orders.net_revenue_amount), 0) as net')
            ->selectRaw('coalesce(sum('.self::ORDER_TAX.'), 0) as tax')
            ->selectRaw('coalesce(sum(orders.discount_amount), 0) as discount')
            ->selectRaw('coalesce(sum('.self::ORDER_SERVICE.'), 0) as service')
            ->selectRaw('coalesce(sum(orders.gateway_fee_amount), 0) as fees')
            ->selectRaw('count(*) filter (where orders.gateway is not null and orders.gateway_fee_amount is null) as fees_pending')
            ->selectRaw("count(*) filter (where orders.channel = 'door') as door_orders")
            ->selectRaw("count(*) filter (where orders.channel = 'online') as online_orders")
            ->selectRaw('count(distinct orders.organization_id) as organizations')
            ->selectRaw('count(distinct orders.event_id) as events')
            ->first();

        $tickets = (int) $this->sold($currency, $period, $scope)
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->whereNotNull('order_lines.ticket_type_id')
            ->sum('order_lines.quantity');

        $refunds = $this->refunds($currency, $period, $scope)
            ->selectRaw('count(*) as refunds')
            ->selectRaw('coalesce(sum(refunds.amount), 0) as amount')
            ->selectRaw('coalesce(sum('.self::REFUND_SERVICE.'), 0) as service')
            ->first();

        $gross = (int) $orders->gross;
        $service = (int) $orders->service;
        $fees = (int) $orders->fees;
        $refunded = (int) $refunds->amount;
        $refundedService = (int) $refunds->service;

        return [
            'orders' => (int) $orders->orders,
            'tickets' => $tickets,
            'gross' => $gross,
            'net' => (int) $orders->net,
            'tax' => (int) $orders->tax,
            'discount' => (int) $orders->discount,
            'service' => $service,
            'fees' => $fees,
            'fees_pending' => (int) $orders->fees_pending,
            'refunds' => (int) $refunds->refunds,
            'refunded' => $refunded,
            'refunded_service' => $refundedService,
            'net_take' => $service - $fees - $refundedService,
            'refund_rate' => self::ratio($refunded, $gross),
            'door_orders' => (int) $orders->door_orders,
            'online_orders' => (int) $orders->online_orders,
            'organizations' => (int) $orders->organizations,
            'events' => (int) $orders->events,
            'average_order' => (int) $orders->orders > 0 ? intdiv($gross, (int) $orders->orders) : null,
        ];
    }

    /**
     * The same figures bucket by bucket, for charts and sparklines.
     *
     * Buckets are cut in the market's own clock, and every bucket in the
     * period is present — a quiet day is a zero, not a gap.
     *
     * @param  array{organization_id?: string, event_id?: string}  $scope
     * @return array{buckets: list<string>, labels: list<string>, gross: list<int>, net: list<int>, service: list<int>, fees: list<int>, orders: list<int>, tickets: list<int>, refunded: list<int>, refunded_service: list<int>, net_take: list<int>}
     */
    protected function series(string $currency, Period $period, array $scope = []): array
    {
        $bucket = "date_trunc('".$period->granularity()."', ".self::SOLD_AT.' at time zone ?)::date';

        $orders = $this->sold($currency, $period, $scope)
            ->selectRaw("to_char({$bucket}, 'YYYY-MM-DD') as bucket", [$period->timezone])
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->selectRaw('coalesce(sum(orders.net_revenue_amount), 0) as net')
            ->selectRaw('coalesce(sum('.self::ORDER_SERVICE.'), 0) as service')
            ->selectRaw('coalesce(sum(orders.gateway_fee_amount), 0) as fees')
            ->groupByRaw('1')
            ->get()
            ->keyBy('bucket');

        $tickets = $this->sold($currency, $period, $scope)
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->whereNotNull('order_lines.ticket_type_id')
            ->selectRaw("to_char({$bucket}, 'YYYY-MM-DD') as bucket", [$period->timezone])
            ->selectRaw('coalesce(sum(order_lines.quantity), 0) as tickets')
            ->groupByRaw('1')
            ->pluck('tickets', 'bucket');

        $refundBucket = "date_trunc('".$period->granularity()."', refunds.created_at at time zone ?)::date";

        $refunds = $this->refunds($currency, $period, $scope)
            ->selectRaw("to_char({$refundBucket}, 'YYYY-MM-DD') as bucket", [$period->timezone])
            ->selectRaw('coalesce(sum(refunds.amount), 0) as amount')
            ->selectRaw('coalesce(sum('.self::REFUND_SERVICE.'), 0) as service')
            ->groupByRaw('1')
            ->get()
            ->keyBy('bucket');

        $out = ['buckets' => $period->buckets(), 'labels' => $period->bucketLabels()];

        foreach (['gross', 'net', 'service', 'fees', 'orders', 'tickets', 'refunded', 'refunded_service', 'net_take'] as $key) {
            $out[$key] = [];
        }

        foreach ($out['buckets'] as $bucket) {
            $row = $orders->get($bucket);
            $refund = $refunds->get($bucket);

            $out['gross'][] = (int) ($row->gross ?? 0);
            $out['net'][] = (int) ($row->net ?? 0);
            $out['service'][] = (int) ($row->service ?? 0);
            $out['fees'][] = (int) ($row->fees ?? 0);
            $out['orders'][] = (int) ($row->orders ?? 0);
            $out['tickets'][] = (int) ($tickets[$bucket] ?? 0);
            $out['refunded'][] = (int) ($refund->amount ?? 0);
            $out['refunded_service'][] = (int) ($refund->service ?? 0);
            $out['net_take'][] = (int) ($row->service ?? 0) - (int) ($row->fees ?? 0) - (int) ($refund->service ?? 0);
        }

        return $out;
    }

    /**
     * Where the money from a slice of sales went.
     *
     * Of everything buyers paid for the orders in the slice: what the
     * organizer keeps, what the platform keeps, what is owed to a tax
     * authority, and what went back to buyers. Refunds are those made against
     * these same orders, whenever they were made, so the four parts add up
     * to the gross exactly — a part-of-whole that did not would be a lie
     * drawn as a circle.
     *
     * @param  array{organization_id?: string, event_id?: string}  $scope
     * @return array{gross: int, organizer: int, platform: int, tax: int, refunded: int}
     */
    protected function split(string $currency, ?Period $period = null, array $scope = []): array
    {
        $sold = $this->sold($currency, $period, $scope);

        $orders = (clone $sold)
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->selectRaw('coalesce(sum(orders.net_revenue_amount), 0) as net')
            ->selectRaw('coalesce(sum('.self::ORDER_TAX.'), 0) as tax')
            ->selectRaw('coalesce(sum('.self::ORDER_SERVICE.'), 0) as service')
            ->first();

        $returned = DB::table('refunds')
            ->where('refunds.status', 'succeeded')
            ->whereIn('refunds.order_id', (clone $sold)->select('orders.id'))
            ->selectRaw('coalesce(sum(refunds.amount), 0) as amount')
            ->selectRaw('coalesce(sum('.self::REFUND_TAX.'), 0) as tax')
            ->selectRaw('coalesce(sum('.self::REFUND_SERVICE.'), 0) as service')
            ->first();

        $refundedNet = (int) $returned->amount - (int) $returned->tax - (int) $returned->service;

        return [
            'gross' => (int) $orders->gross,
            'organizer' => (int) $orders->net - $refundedNet,
            'platform' => (int) $orders->service - (int) $returned->service,
            'tax' => (int) $orders->tax - (int) $returned->tax,
            'refunded' => (int) $returned->amount,
        ];
    }

    /**
     * The split's kept parts bucket by bucket, dated when the sale was made.
     *
     * A refund comes off the bucket its order was sold in, whenever it was
     * made — the same rule as split() — so the buckets add up to its
     * organizer, platform and tax parts exactly, and an order refunded in
     * full keeps nothing on any day.
     *
     * @param  array{organization_id?: string, event_id?: string}  $scope
     * @return array{organizer: list<int>, platform: list<int>, tax: list<int>}
     */
    protected function splitSeries(string $currency, Period $period, array $scope = []): array
    {
        $bucket = "to_char(date_trunc('".$period->granularity()."', ".self::SOLD_AT." at time zone ?)::date, 'YYYY-MM-DD')";

        $orders = $this->sold($currency, $period, $scope)
            ->selectRaw("{$bucket} as bucket", [$period->timezone])
            ->selectRaw('coalesce(sum(orders.net_revenue_amount), 0) as net')
            ->selectRaw('coalesce(sum('.self::ORDER_TAX.'), 0) as tax')
            ->selectRaw('coalesce(sum('.self::ORDER_SERVICE.'), 0) as service')
            ->groupByRaw('1')
            ->get()
            ->keyBy('bucket');

        $returned = $this->sold($currency, $period, $scope)
            ->join('refunds', 'refunds.order_id', '=', 'orders.id')
            ->where('refunds.status', 'succeeded')
            ->selectRaw("{$bucket} as bucket", [$period->timezone])
            ->selectRaw('coalesce(sum(refunds.amount), 0) as amount')
            ->selectRaw('coalesce(sum('.self::REFUND_TAX.'), 0) as tax')
            ->selectRaw('coalesce(sum('.self::REFUND_SERVICE.'), 0) as service')
            ->groupByRaw('1')
            ->get()
            ->keyBy('bucket');

        $out = ['organizer' => [], 'platform' => [], 'tax' => []];

        foreach ($period->buckets() as $key) {
            $row = $orders->get($key);
            $refund = $returned->get($key);
            $refundTax = (int) ($refund->tax ?? 0);
            $refundService = (int) ($refund->service ?? 0);

            $out['organizer'][] = (int) ($row->net ?? 0) - ((int) ($refund->amount ?? 0) - $refundTax - $refundService);
            $out['platform'][] = (int) ($row->service ?? 0) - $refundService;
            $out['tax'][] = (int) ($row->tax ?? 0) - $refundTax;
        }

        return $out;
    }

    /** Live tickets on the event in the outer query. */
    protected static function liveTickets(): Builder
    {
        return DB::table('tickets')
            ->whereColumn('tickets.event_id', 'events.id')
            ->whereIn('tickets.status', self::LIVE_TICKETS);
    }

    /**
     * Places on sale for the event in the outer query, or null when any tier
     * is unlimited — a room with no ceiling has no percentage full.
     */
    protected static function capacity(): Builder
    {
        return DB::table('ticket_types')
            ->whereColumn('ticket_types.event_id', 'events.id')
            ->whereNull('ticket_types.deleted_at')
            ->selectRaw('case when count(*) = 0 or bool_or(ticket_types.quantity_available is null) then null else sum(ticket_types.quantity_available) end');
    }

    /** A share, or null when there is nothing to divide by. */
    public static function ratio(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? $part / $whole : null;
    }

    /**
     * Relative change from one period to the next, or null when the earlier
     * one was zero — "up from nothing" has no percentage.
     */
    public static function change(int|float|null $now, int|float|null $before): ?float
    {
        if ($now === null || $before === null || $before == 0) {
            return null;
        }

        return ($now - $before) / abs($before);
    }
}
