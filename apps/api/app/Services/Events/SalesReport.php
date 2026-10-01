<?php

namespace App\Services\Events;

use App\Models\AddOn;
use App\Models\Code;
use App\Models\Event;
use App\Models\ShareLink;
use App\Services\Door\DoorSales;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where an event's sales came from: which days, which tiers, which codes.
 *
 * The overview has always said what an event took in total. The questions an
 * organizer actually asks the week before are narrower — is Early Bird gone,
 * did the Instagram push on Tuesday do anything, is Ade's promoter link
 * selling — and a total answers none of them.
 *
 * Two different counts, on purpose:
 *
 * - By day and by code count what was sold at the time: order lines on paid
 *   orders, at the price paid after discounts. A sale that was later refunded
 *   still happened on the day it happened.
 * - By tier counts tickets that are good now — what is left to fill the room
 *   with — and splits out complimentary tickets, which take a place without
 *   being a sale.
 *
 * Refunds are not taken out of the by-day and by-code figures; the ledger
 * totals on the overview carry them, and the screen says so.
 */
class SalesReport
{
    /** How far back the chart goes. A months-long on-sale window shows its last four months. */
    public const MAX_DAYS = 120;

    private const GOOD = ['valid', 'checked_in'];

    /** The one row the friend-discount rewards are counted under. */
    private const REWARDS = 'share:rewards';

    /** @return array<string, mixed> */
    public function for(Event $event): array
    {
        return [
            'currency' => $event->currency,
            'timezone' => $event->timezone,
            'ticket_types' => $this->byTicketType($event),
            'add_ons' => $this->byAddOn($event),
            // What was taken in a doorway, from the same service the door
            // screen reads. An organizer reconciling the money should not
            // have to open another tab to find the half of it that arrived
            // in cash.
            'door' => app(DoorSales::class)->takings($event),
            'days' => $this->byDay($event),
            'codes' => $this->byCode($event),
            // Looked, bought, came, from where, and against last time.
            'insights' => app(Insights::class)->for($event),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function byTicketType(Event $event): array
    {
        $tickets = DB::table('tickets')
            ->where('event_id', $event->id)
            ->whereIn('status', self::GOOD)
            ->groupBy('ticket_type_id')
            ->selectRaw('ticket_type_id')
            ->selectRaw('count(*) filter (where order_id is not null) as sold')
            ->selectRaw('count(*) filter (where order_id is null) as comps')
            ->selectRaw('coalesce(sum(admits), 0) as people')
            ->selectRaw('coalesce(sum(admitted_count), 0) as arrived')
            ->get()
            ->keyBy('ticket_type_id');

        $revenue = $this->paidLines($event)
            ->groupBy('order_lines.ticket_type_id')
            ->selectRaw('order_lines.ticket_type_id')
            ->selectRaw('sum(order_lines.line_total_amount - order_lines.discount_amount) as revenue')
            ->pluck('revenue', 'ticket_type_id');

        return $event->ticketTypes()
            ->orderBy('sort_order')
            ->get()
            ->map(function ($type) use ($tickets, $revenue, $event) {
                $row = $tickets->get($type->id);

                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'status' => $type->status,
                    'price' => ['amount' => (int) $type->price_amount, 'currency' => $event->currency],
                    'capacity' => $type->quantity_available,
                    'sold' => (int) ($row->sold ?? 0),
                    'comps' => (int) ($row->comps ?? 0),
                    // A Table of 5 is one ticket and five people. The door
                    // counts people, so arrivals are measured against people.
                    'people' => (int) ($row->people ?? 0),
                    'arrived' => (int) ($row->arrived ?? 0),
                    'revenue' => ['amount' => (int) ($revenue[$type->id] ?? 0), 'currency' => $event->currency],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * What was sold alongside the tickets.
     *
     * Its own section rather than a row among the tiers: an add-on sells no
     * places and fills no room, so putting a bottle in the list an organizer
     * reads capacity from would make both numbers wrong.
     *
     * Removed add-ons are included when they sold anything. An organizer
     * taking a bottle off the list does not unsell the ones already bought,
     * and a report that quietly drops them stops adding up.
     *
     * @return list<array<string, mixed>>
     */
    private function byAddOn(Event $event): array
    {
        $sold = $this->paidLines($event)
            ->whereNotNull('order_lines.add_on_id')
            ->groupBy('order_lines.add_on_id')
            ->selectRaw('order_lines.add_on_id')
            ->selectRaw('sum(order_lines.quantity) as sold')
            ->selectRaw('sum(order_lines.line_total_amount - order_lines.discount_amount) as revenue')
            ->get()
            ->keyBy('add_on_id');

        return AddOn::withTrashed()
            ->where('event_id', $event->id)
            ->where(fn ($query) => $query
                ->whereNull('deleted_at')
                ->orWhereIn('id', $sold->keys()))
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(function (AddOn $addOn) use ($sold, $event) {
                $row = $sold->get($addOn->id);

                return [
                    'id' => $addOn->id,
                    'name' => $addOn->name,
                    'status' => $addOn->trashed() ? 'removed' : $addOn->status,
                    'price' => ['amount' => (int) $addOn->price_amount, 'currency' => $event->currency],
                    'capacity' => $addOn->quantity_available,
                    'sold' => (int) ($row->sold ?? 0),
                    'revenue' => ['amount' => (int) ($row->revenue ?? 0), 'currency' => $event->currency],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Sales per day, in the event's own time zone.
     *
     * A Lagos event's midnight is not the server's. Days with no sales are
     * filled in so a chart shows the quiet days as quiet, rather than closing
     * the gap and making two busy days look consecutive.
     *
     * @return list<array{date: string, orders: int, tickets: int, revenue: int}>
     */
    private function byDay(Event $event): array
    {
        $rows = $this->paidLines($event)
            ->groupByRaw('1')
            ->selectRaw('(coalesce(orders.paid_at, orders.created_at) at time zone ?)::date as day', [$event->timezone])
            ->selectRaw('count(distinct orders.id) as orders')
            // Tickets counted as tickets; revenue counts everything sold,
            // because a bottle is money the organizer took that day.
            ->selectRaw('sum(order_lines.quantity) filter (where order_lines.ticket_type_id is not null) as tickets')
            ->selectRaw('sum(order_lines.line_total_amount - order_lines.discount_amount) as revenue')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        if ($rows->isEmpty()) {
            return [];
        }

        $zone = $event->timezone;
        $first = CarbonImmutable::parse((string) $rows->keys()->first(), $zone);
        $today = CarbonImmutable::now($zone)->startOfDay();
        $last = CarbonImmutable::parse((string) $rows->keys()->last(), $zone)->max($today);

        // Past the event, the chart stops at the last sale rather than running
        // on through months of nothing.
        if ($event->starts_at->isPast()) {
            $last = CarbonImmutable::parse((string) $rows->keys()->last(), $zone);
        }

        $first = $first->max($last->subDays(self::MAX_DAYS - 1));

        $days = [];

        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());

            $days[] = [
                'date' => $day->toDateString(),
                'orders' => (int) ($row->orders ?? 0),
                'tickets' => (int) ($row->tickets ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Sales that came through a code or a promoter link.
     *
     * A promoter link and a discount code are the same record, so one row
     * each; an order that arrived on a link whose code has since been deleted
     * still shows, under the slug it carried.
     *
     * @return list<array<string, mixed>>
     */
    private function byCode(Event $event): array
    {
        $rows = $this->paidLines($event)
            ->where(fn ($query) => $query->whereNotNull('orders.code_id')->orWhereNotNull('orders.ref_slug'))
            ->groupBy('orders.code_id', 'orders.ref_slug')
            ->selectRaw('orders.code_id, orders.ref_slug')
            ->selectRaw('count(distinct orders.id) as orders')
            ->selectRaw('sum(order_lines.quantity) filter (where order_lines.ticket_type_id is not null) as tickets')
            ->selectRaw('sum(order_lines.discount_amount) as discount')
            ->selectRaw('sum(order_lines.line_total_amount - order_lines.discount_amount) as revenue')
            ->get();

        $codes = Code::withTrashed()->whereIn('id', $rows->pluck('code_id')->filter())->get()->keyBy('id');

        /*
         * A friend discount, said as one. Its hidden code is one row named for
         * what it is, not for a code nobody ever typed; a friend's link that
         * came after the offer ended (so took nothing off) is counted there
         * too, not as a stray ?ref=; and the buyers' rewards, one single-use
         * code each, are one row between them.
         */
        $friendCode = Code::withTrashed()->where('event_id', $event->id)->where('purpose', Code::SHARE_FRIEND)->first();
        $friendLinks = $friendCode === null ? collect() : ShareLink::query()
            ->where('event_id', $event->id)
            ->whereIn('slug', $rows->pluck('ref_slug')->filter()->map(fn ($slug) => strtolower((string) $slug))->unique()->values())
            ->pluck('slug')
            ->flip();

        if ($friendCode !== null) {
            $codes->put($friendCode->id, $friendCode);
        }

        $keyOf = function ($row) use ($codes, $friendCode, $friendLinks) {
            $code = $row->code_id ? $codes->get($row->code_id) : null;

            if ($code?->purpose === Code::SHARE_REWARD) {
                return self::REWARDS;
            }

            if ($code === null && $friendCode !== null && $row->ref_slug !== null && $friendLinks->has(strtolower((string) $row->ref_slug))) {
                return $friendCode->id;
            }

            return $row->code_id ?? 'ref:'.$row->ref_slug;
        };

        return $rows
            // One row per code, even when some of its orders carried the slug
            // and some did not.
            ->groupBy($keyOf)
            ->map(function ($group, $key) use ($codes, $event) {
                $first = $group->first();
                $code = $key === self::REWARDS ? null : $codes->get((string) $key);

                $shared = match (true) {
                    $key === self::REWARDS => [
                        'code_id' => null,
                        'code' => 'Friend rewards',
                        'label' => 'Codes buyers earned when a friend bought through their link',
                    ],
                    $code?->purpose === Code::SHARE_FRIEND => [
                        'code_id' => $code->id,
                        'code' => 'Friend’s discount',
                        'label' => 'Friends who bought through a buyer’s link',
                    ],
                    default => null,
                };

                if ($shared !== null) {
                    return $shared + [
                        'promoter' => null,
                        'ref_slug' => null,
                        'deleted' => false,
                        'orders' => (int) $group->sum('orders'),
                        'tickets' => (int) $group->sum('tickets'),
                        'discount' => ['amount' => (int) $group->sum('discount'), 'currency' => $event->currency],
                        'revenue' => ['amount' => (int) $group->sum('revenue'), 'currency' => $event->currency],
                    ];
                }

                return [
                    'code_id' => $first->code_id,
                    'code' => $code?->code,
                    'label' => $code?->label,
                    'promoter' => $code?->promoter_name,
                    'ref_slug' => $code?->ref_slug ?? $first->ref_slug,
                    'deleted' => (bool) $code?->trashed(),
                    'orders' => (int) $group->sum('orders'),
                    'tickets' => (int) $group->sum('tickets'),
                    'discount' => ['amount' => (int) $group->sum('discount'), 'currency' => $event->currency],
                    'revenue' => ['amount' => (int) $group->sum('revenue'), 'currency' => $event->currency],
                ];
            })
            ->sortByDesc(fn ($row) => $row['tickets'])
            ->values()
            ->all();
    }

    private function paidLines(Event $event): Builder
    {
        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.event_id', $event->id)
            ->whereIn('orders.status', Code::PAID_STATUSES);
    }
}
