<?php

namespace App\Services\Analytics;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * How one night sold and ran, and how every night compares.
 *
 * Over the event's whole life rather than a period: a night sells in its own
 * currency from the day it goes on sale until the doors close, and that is the
 * span every figure here covers.
 */
class EventMetrics extends Metrics
{
    /** How far before the night the pace line reaches. Earlier sales start it off. */
    public const PACE_DAYS = 90;

    /** The door chart's slot, and how many of them it will draw — a day's worth. */
    public const SLOT_MINUTES = 15;

    public const MAX_SLOTS = 96;

    /** @return array<string, mixed> */
    public function for(Event $event): array
    {
        return $this->remember('event', [$event->id, $event->updated_at?->getTimestamp()], function () use ($event) {
            $scope = ['event_id' => $event->id];

            return [
                'totals' => $this->totals($event->currency, null, $scope),
                'split' => $this->split($event->currency, null, $scope),
                'attendance' => $this->attendance($event),
                'views' => (int) DB::table('event_views')->where('event_id', $event->id)->sum(DB::raw('views + embed_views')),
                'pace' => $this->pace($event),
                'ticket_types' => $this->ticketTypes($event),
                'codes' => $this->codes($event),
                'check_ins' => $this->checkIns($event),
                'channels' => $this->channels($event),
                'refunds' => $this->recentRefunds($event),
            ];
        });
    }

    /**
     * Who holds a ticket that still admits somebody, and how many came.
     *
     * @return array{sold: int, comps: int, people: int, arrived: int, capacity: ?int, rate: ?float}
     */
    private function attendance(Event $event): array
    {
        $row = DB::table('tickets')
            ->where('event_id', $event->id)
            ->whereIn('status', self::LIVE_TICKETS)
            ->selectRaw('count(*) filter (where order_id is not null) as sold')
            ->selectRaw('count(*) filter (where order_id is null) as comps')
            ->selectRaw('coalesce(sum(admits), 0) as people')
            ->selectRaw('coalesce(sum(admitted_count), 0) as arrived')
            ->first();

        $capacity = DB::table('events')->where('events.id', $event->id)->selectSub(self::capacity(), 'capacity')->value('capacity');

        return [
            'sold' => (int) $row->sold,
            'comps' => (int) $row->comps,
            'people' => (int) $row->people,
            'arrived' => (int) $row->arrived,
            'capacity' => $capacity === null ? null : (int) $capacity,
            'rate' => self::ratio((int) $row->arrived, (int) $row->people),
        ];
    }

    /**
     * Tickets sold, added up day by day towards the night.
     *
     * Indexed by days before the doors so two nights line up. Sales made
     * after the doors opened — the door itself — land on day zero.
     *
     * @return list<array{days_before: int, tickets: int}>
     */
    private function pace(Event $event): array
    {
        $start = CarbonImmutable::parse($event->starts_at);

        $sold = $this->sold($event->currency, null, ['event_id' => $event->id])
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->whereNotNull('order_lines.ticket_type_id')
            ->selectRaw('greatest(0, floor(extract(epoch from (?::timestamptz - '.self::SOLD_AT.')) / 86400))::int as days_before', [$start->utc()->format('Y-m-d H:i:sP')])
            ->selectRaw('coalesce(sum(order_lines.quantity), 0) as tickets')
            ->groupByRaw('1')
            ->pluck('tickets', 'days_before')
            ->mapWithKeys(fn ($tickets, $day) => [(int) $day => (int) $tickets]);

        if ($sold->isEmpty()) {
            return [];
        }

        $until = $start->isFuture() ? (int) floor(CarbonImmutable::now()->diffInDays($start)) : 0;
        $top = max($until, min((int) $sold->keys()->max(), $until + self::PACE_DAYS));

        $running = (int) $sold->filter(fn ($tickets, $day) => $day > $top)->sum();
        $points = [];

        for ($day = $top; $day >= $until; $day--) {
            $running += $sold[$day] ?? 0;
            $points[] = ['days_before' => $day, 'tickets' => $running];
        }

        return $points;
    }

    /**
     * Sales per tier. A tier taken off sale still shows if it sold anything:
     * removing it does not unsell what it sold.
     *
     * @return list<array{id: string, name: string, capacity: ?int, tickets: int, revenue: int, removed: bool}>
     */
    private function ticketTypes(Event $event): array
    {
        $sold = $this->sold($event->currency, null, ['event_id' => $event->id])
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->whereNotNull('order_lines.ticket_type_id')
            ->groupBy('order_lines.ticket_type_id')
            ->selectRaw('order_lines.ticket_type_id as id')
            ->selectRaw('coalesce(sum(order_lines.quantity), 0) as tickets')
            ->selectRaw('coalesce(sum(order_lines.line_total_amount - order_lines.discount_amount), 0) as revenue')
            ->get()
            ->keyBy('id');

        return DB::table('ticket_types')
            ->where('event_id', $event->id)
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhereIn('id', $sold->keys()->all()))
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get(['id', 'name', 'quantity_available', 'deleted_at'])
            ->map(fn ($type) => [
                'id' => (string) $type->id,
                'name' => (string) $type->name,
                'capacity' => $type->quantity_available === null ? null : (int) $type->quantity_available,
                'tickets' => (int) ($sold[$type->id]->tickets ?? 0),
                'revenue' => (int) ($sold[$type->id]->revenue ?? 0),
                'removed' => $type->deleted_at !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * Discount codes and promoter links that sold, by orders.
     *
     * Discount codes only — the words a buyer typed at checkout. Ticket
     * codes, the ones that open the door, never appear in a list.
     *
     * @return array{orders: int, codes: list<array{code: string, label: ?string, orders: int, discount: int, gross: int, removed: bool}>}
     */
    private function codes(Event $event, int $limit = 10): array
    {
        $rows = $this->sold($event->currency, null, ['event_id' => $event->id])
            ->whereNotNull('orders.code_id')
            ->groupBy('orders.code_id')
            ->selectRaw('orders.code_id as id')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.discount_amount), 0) as discount')
            ->selectRaw('coalesce(sum(orders.total_amount), 0) as gross')
            ->orderByDesc('orders')
            ->get();

        $codes = DB::table('codes')
            ->whereIn('id', $rows->take($limit)->pluck('id')->all())
            ->get(['id', 'code', 'label', 'deleted_at'])
            ->keyBy('id');

        return [
            'orders' => (int) $rows->sum('orders'),
            'codes' => $rows->take($limit)->map(fn ($row) => [
                'code' => (string) ($codes[$row->id]->code ?? 'Removed code'),
                'label' => $codes[$row->id]->label ?? null,
                'orders' => (int) $row->orders,
                'discount' => (int) $row->discount,
                'gross' => (int) $row->gross,
                'removed' => ($codes[$row->id]->deleted_at ?? null) !== null,
            ])->values()->all(),
        ];
    }

    /**
     * People through the door, fifteen minutes at a time, on the venue's clock.
     *
     * People rather than scans: a table for five scanned once is five
     * arrivals. Scans that were turned away are counted beside it.
     *
     * @return array{slots: list<string>, labels: list<string>, people: list<int>, total: int, turned_away: int, truncated: bool}
     */
    private function checkIns(Event $event): array
    {
        $rows = DB::table('ticket_scans')
            ->where('event_id', $event->id)
            ->where('result', 'accepted')
            ->selectRaw("to_char(date_bin('".self::SLOT_MINUTES." minutes', ticket_scans.scanned_at at time zone ?, timestamp '2000-01-01'), 'YYYY-MM-DD HH24:MI') as slot", [$event->timezone])
            ->selectRaw('coalesce(sum(ticket_scans.admitted), 0) as people')
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->pluck('people', 'slot');

        $turnedAway = DB::table('ticket_scans')->where('event_id', $event->id)->where('result', '!=', 'accepted')->count();

        $out = ['slots' => [], 'labels' => [], 'people' => [], 'total' => (int) $rows->sum(), 'turned_away' => $turnedAway, 'truncated' => false];

        if ($rows->isEmpty()) {
            return $out;
        }

        // Wall-clock arithmetic: the slots are already in the venue's time,
        // so stepping through them must not apply a daylight-saving jump.
        $cursor = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $rows->keys()->first(), 'UTC');
        $last = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $rows->keys()->last(), 'UTC');

        while ($cursor->lte($last)) {
            if (count($out['slots']) === self::MAX_SLOTS) {
                $out['truncated'] = true;
                break;
            }

            $key = $cursor->format('Y-m-d H:i');
            $out['slots'][] = $key;
            $out['labels'][] = $cursor->format('H:i');
            $out['people'][] = (int) ($rows[$key] ?? 0);
            $cursor = $cursor->addMinutes(self::SLOT_MINUTES);
        }

        return $out;
    }

    /** @return array<string, array{orders: int, gross: int, tickets: int}> */
    private function channels(Event $event): array
    {
        $scope = ['event_id' => $event->id];

        $orders = $this->sold($event->currency, null, $scope)
            ->groupBy('orders.channel')
            ->selectRaw('orders.channel as channel, count(*) as orders, coalesce(sum(orders.total_amount), 0) as gross')
            ->get()
            ->keyBy('channel');

        $tickets = $this->sold($event->currency, null, $scope)
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
    }

    /**
     * The latest refunds, failed ones included — a refund the processor
     * refused is something an organizer will ask about.
     *
     * @return list<array{reference: string, amount: int, status: string, reason: ?string, created_at: string}>
     */
    private function recentRefunds(Event $event, int $limit = 10): array
    {
        return DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->where('refunds.event_id', $event->id)
            ->orderByDesc('refunds.created_at')
            ->limit($limit)
            ->get(['orders.reference', 'refunds.amount', 'refunds.status', 'refunds.reason', 'refunds.created_at'])
            ->map(fn ($row) => [
                'reference' => (string) $row->reference,
                'amount' => (int) $row->amount,
                'status' => (string) $row->status,
                'reason' => $row->reason,
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    /**
     * Every event with its figures, as subqueries on each row.
     *
     * Each event sells in one currency, so every amount on a row is in that
     * row's currency; the table never totals a column. Wrapped as a derived
     * table named "events" so the measures, and the rates divided from them,
     * are plain columns to sort and filter on.
     */
    public function table(): EloquentBuilder
    {
        return Event::query()
            ->fromSub($this->measures(), 'events')
            ->select('events.*')
            ->selectRaw('case when events.capacity > 0 then events.live_sold::numeric / events.capacity end as fill_rate')
            ->selectRaw('case when events.people > 0 then events.arrived::numeric / events.people end as check_in_rate')
            ->selectRaw('case when events.gross > 0 then events.refunded::numeric / events.gross end as refund_rate')
            ->selectRaw('case when events.views > 0 then events.online_orders::numeric / events.views end as conversion');
    }

    private function measures(): EloquentBuilder
    {
        $sold = fn () => DB::table('orders')
            ->whereColumn('orders.event_id', 'events.id')
            ->whereRaw(self::SOLD_SQL);

        // Removed events included here and left out by the outer query's own
        // soft-delete scope, so a listing can still ask for them.
        return Event::withTrashed()
            ->select('events.*')
            ->selectSub($sold()->selectRaw('coalesce(sum(orders.total_amount), 0)'), 'gross')
            ->selectSub($sold()->selectRaw('count(*)'), 'orders_count')
            ->selectSub($sold()->where('orders.channel', 'online')->selectRaw('count(*)'), 'online_orders')
            ->selectSub($sold()->where('orders.channel', 'door')->selectRaw('count(*)'), 'door_orders')
            ->selectSub($sold()
                ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
                ->whereNotNull('order_lines.ticket_type_id')
                ->selectRaw('coalesce(sum(order_lines.quantity), 0)'), 'tickets_sold')
            ->selectSub(DB::table('refunds')
                ->whereColumn('refunds.event_id', 'events.id')
                ->where('refunds.status', 'succeeded')
                ->selectRaw('coalesce(sum(refunds.amount), 0)'), 'refunded')
            ->selectSub(self::capacity(), 'capacity')
            ->selectSub(self::liveTickets()->whereNotNull('tickets.order_id')->selectRaw('count(*)'), 'live_sold')
            ->selectSub(self::liveTickets()->selectRaw('coalesce(sum(tickets.admits), 0)'), 'people')
            ->selectSub(self::liveTickets()->selectRaw('coalesce(sum(tickets.admitted_count), 0)'), 'arrived')
            ->selectSub(DB::table('event_views')
                ->whereColumn('event_views.event_id', 'events.id')
                ->selectRaw('coalesce(sum(event_views.views + event_views.embed_views), 0)'), 'views');
    }
}
