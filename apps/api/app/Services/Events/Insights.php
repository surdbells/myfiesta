<?php

namespace App\Services\Events;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How a night is doing, beyond what it sold.
 *
 * Four questions an organizer asks and the sales table could not answer. How
 * many who looked bought. How many who bought came — ours to give, since we
 * run the door. Where the buyers came from. And how this compares with the
 * last one, which is the only benchmark that means anything to them: not an
 * industry average, their own last Friday.
 */
class Insights
{
    /** How far back the pace line reaches. */
    public const PACE_DAYS = 60;

    private const LIVE_ORDERS = ['paid', 'partially_refunded'];

    private const LIVE_TICKETS = ['valid', 'checked_in'];

    /** @return array<string, mixed> */
    public function for(Event $event): array
    {
        $previous = $this->previous($event);

        return [
            'summary' => $this->summary($event),
            'sources' => $this->sources($event),
            'previous' => $previous ? [
                'id' => $previous->id,
                'title' => $previous->title,
                'starts_at' => $previous->starts_at?->toIso8601String(),
                'summary' => $this->summary($previous),
            ] : null,
            'pace' => [
                'this' => $this->pace($event),
                'previous' => $previous ? $this->pace($previous) : null,
            ],
        ];
    }

    /**
     * The headline numbers, the same for any event so two can sit side by side.
     *
     * @return array<string, mixed>
     */
    public function summary(Event $event): array
    {
        $orders = DB::table('orders')
            ->where('event_id', $event->id)
            ->whereIn('status', self::LIVE_ORDERS)
            ->selectRaw('count(*) as orders')
            ->selectRaw("count(*) filter (where channel <> 'door') as online_orders")
            ->selectRaw('coalesce(sum(net_revenue_amount), 0) as revenue')
            ->first();

        $tickets = DB::table('tickets')
            ->where('event_id', $event->id)
            ->whereIn('status', self::LIVE_TICKETS)
            ->selectRaw('count(*) as tickets')
            ->selectRaw('coalesce(sum(admits), 0) as people')
            ->selectRaw('coalesce(sum(admitted_count), 0) as arrived')
            ->first();

        $views = DB::table('event_views')
            ->where('event_id', $event->id)
            ->selectRaw('coalesce(sum(views), 0) as views, coalesce(sum(embed_views), 0) as embed_views')
            ->first();

        $looked = (int) $views->views + (int) $views->embed_views;
        $started = $event->starts_at !== null && $event->starts_at->isPast();
        $people = (int) $tickets->people;

        return [
            'orders' => (int) $orders->orders,
            'tickets' => (int) $tickets->tickets,
            'revenue' => ['amount' => (int) $orders->revenue, 'currency' => $event->currency],
            'views' => (int) $views->views,
            'embed_views' => (int) $views->embed_views,
            // Online orders over page views: a door sale never saw the page.
            // Null rather than a rate when there is nothing to divide by —
            // and views were not counted before September 2026, so an older
            // night has none.
            'conversion' => $looked > 0 ? round((int) $orders->online_orders / $looked, 4) : null,
            'people' => $people,
            'arrived' => (int) $tickets->arrived,
            // Only once the doors have opened: before that, nobody arriving is
            // not a turnout, it is Tuesday.
            'attendance' => $started && $people > 0 ? round((int) $tickets->arrived / $people, 4) : null,
            'started' => $started,
        ];
    }

    /**
     * Where the orders came from, each counted once.
     *
     * In order of how deliberately somebody brought them: the door, then an
     * email this organizer sent, then a buyer's friend's link (whose ref is
     * on the order like a promoter's, but which nobody here gave out), then
     * a promoter's link, then the widget on the organizer's own site, and
     * everything else is somebody who found the page themselves.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(Event $event): array
    {
        $rows = DB::table('orders')
            ->leftJoin('campaigns', 'campaigns.ref', '=', 'orders.ref_slug')
            ->where('orders.event_id', $event->id)
            ->whereIn('orders.status', self::LIVE_ORDERS)
            ->selectRaw("
                case
                    when orders.channel = 'door' then 'door'
                    when campaigns.id is not null then 'campaign'
                    when orders.share_link_id is not null or exists (
                        select 1 from codes where codes.id = orders.code_id and codes.purpose = 'share_friend'
                    ) then 'friend'
                    when orders.ref_slug is not null or orders.code_id is not null and exists (
                        select 1 from codes where codes.id = orders.code_id and codes.ref_slug is not null
                    ) then 'link'
                    when orders.embedded then 'embed'
                    else 'direct'
                end as source
            ")
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.net_revenue_amount), 0) as revenue')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        $tickets = DB::table('tickets')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->leftJoin('campaigns', 'campaigns.ref', '=', 'orders.ref_slug')
            ->where('tickets.event_id', $event->id)
            ->whereIn('orders.status', self::LIVE_ORDERS)
            ->whereIn('tickets.status', self::LIVE_TICKETS)
            ->selectRaw("
                case
                    when orders.channel = 'door' then 'door'
                    when campaigns.id is not null then 'campaign'
                    when orders.share_link_id is not null or exists (
                        select 1 from codes where codes.id = orders.code_id and codes.purpose = 'share_friend'
                    ) then 'friend'
                    when orders.ref_slug is not null or orders.code_id is not null and exists (
                        select 1 from codes where codes.id = orders.code_id and codes.ref_slug is not null
                    ) then 'link'
                    when orders.embedded then 'embed'
                    else 'direct'
                end as source
            ")
            ->selectRaw('count(*) as tickets')
            ->groupBy('source')
            ->pluck('tickets', 'source');

        return collect(['direct', 'link', 'friend', 'campaign', 'embed', 'door'])
            ->filter(fn (string $source) => $rows->has($source))
            ->map(fn (string $source) => [
                'source' => $source,
                'orders' => (int) $rows[$source]->orders,
                'tickets' => (int) ($tickets[$source] ?? 0),
                'revenue' => ['amount' => (int) $rows[$source]->revenue, 'currency' => $event->currency],
            ])
            ->values()
            ->all();
    }

    /**
     * The organizer's last night before this one — the comparison they make.
     *
     * One that happened, sold tickets and was not called off. A draft or a
     * cancelled night is not "last time".
     */
    private function previous(Event $event): ?Event
    {
        if ($event->starts_at === null) {
            return null;
        }

        return Event::query()
            ->where('organization_id', $event->organization_id)
            ->whereKeyNot($event->id)
            ->where('kind', 'ticketed')
            ->where('status', 'published')
            ->where('starts_at', '<', $event->starts_at)
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * Tickets sold, counted up day by day towards the night.
     *
     * Indexed by days before the doors, so two nights months apart line up:
     * "by a week out, last time we had sold 140". Only as far as today for a
     * night still to come.
     *
     * @return list<array{days_before: int, tickets: int}>
     */
    private function pace(Event $event): array
    {
        if ($event->starts_at === null) {
            return [];
        }

        $start = CarbonImmutable::parse($event->starts_at);

        $sold = DB::table('tickets')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('tickets.event_id', $event->id)
            ->whereIn('orders.status', self::LIVE_ORDERS)
            ->whereIn('tickets.status', self::LIVE_TICKETS)
            ->whereNotNull('orders.paid_at')
            ->selectRaw('greatest(0, floor(extract(epoch from (?::timestamptz - orders.paid_at)) / 86400))::int as days_before', [$start->toIso8601String()])
            ->selectRaw('count(*) as tickets')
            ->groupBy('days_before')
            ->pluck('tickets', 'days_before');

        // Everything sold earlier than the window counts from its first day,
        // so the line starts where it really was rather than at zero.
        $before = $sold->filter(fn ($n, $day) => (int) $day > self::PACE_DAYS)->sum();

        $until = $start->isFuture() ? max(0, (int) floor(now()->diffInDays($start, false))) : 0;

        $points = [];
        $running = (int) $before;

        for ($day = self::PACE_DAYS; $day >= $until; $day--) {
            $running += (int) ($sold[$day] ?? 0);
            $points[] = ['days_before' => $day, 'tickets' => $running];
        }

        return $points;
    }
}
