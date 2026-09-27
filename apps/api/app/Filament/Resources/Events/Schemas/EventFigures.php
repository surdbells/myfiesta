<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\Listing;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * The key numbers for one event, worked out once per page.
 *
 * Every figure is in the event's own currency — an event sells in exactly one
 * — so nothing here is ever added across currencies.
 */
final class EventFigures
{
    /** @var WeakMap<Event, array<string, mixed>>|null */
    private static ?WeakMap $cache = null;

    /** @return array<string, mixed> */
    public static function for(Event $event): array
    {
        self::$cache ??= new WeakMap;

        return self::$cache[$event] ??= self::compute($event);
    }

    /** @return array<string, mixed> */
    private static function compute(Event $event): array
    {
        $tickets = DB::table('tickets')
            ->where('event_id', $event->id)
            ->whereIn('status', EventsTable::PLACES)
            ->selectRaw('count(*) filter (where order_id is not null) as sold')
            ->selectRaw('count(*) filter (where order_id is null) as comps')
            ->selectRaw('coalesce(sum(admits), 0) as people')
            ->selectRaw('coalesce(sum(admitted_count), 0) as arrived')
            ->first();

        $orders = DB::table('orders')
            ->where('event_id', $event->id)
            ->whereIn('status', EventsTable::TAKEN)
            ->selectRaw('count(*) as orders')
            ->selectRaw("count(*) filter (where channel = 'door') as door_orders")
            ->selectRaw('coalesce(sum(total_amount), 0) as gross')
            ->selectRaw('coalesce(sum(net_revenue_amount), 0) as organizer')
            // The platform's part: any tax inside a service charge is owed
            // to a tax authority, as the admin's reports count it.
            ->selectRaw('coalesce(sum(service_charge_amount - service_charge_tax_amount), 0) as service')
            ->first();

        $refunded = (int) DB::table('refunds')
            ->where('event_id', $event->id)
            ->where('status', 'succeeded')
            ->sum('amount');

        $types = TicketType::query()->where('event_id', $event->id)->get(['quantity_available']);

        $capacity = $types->isEmpty()
            ? null
            : ($types->contains(fn (TicketType $type) => $type->quantity_available === null)
                ? 'unlimited'
                : (int) $types->sum('quantity_available'));

        $currency = $event->currency;

        return [
            'sold' => (int) ($tickets->sold ?? 0),
            'comps' => (int) ($tickets->comps ?? 0),
            'people' => (int) ($tickets->people ?? 0),
            'arrived' => (int) ($tickets->arrived ?? 0),
            'capacity' => $capacity,
            'orders' => (int) ($orders->orders ?? 0),
            'door_orders' => (int) ($orders->door_orders ?? 0),
            'gross' => Listing::format((int) ($orders->gross ?? 0), $currency),
            'organizer' => Listing::format((int) ($orders->organizer ?? 0), $currency),
            'service' => Listing::format((int) ($orders->service ?? 0), $currency),
            'refunded' => Listing::format($refunded, $currency),
        ];
    }
}
