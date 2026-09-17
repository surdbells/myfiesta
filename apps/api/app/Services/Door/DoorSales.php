<?php

namespace App\Services\Door;

use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use Illuminate\Support\Facades\DB;

/**
 * Selling to somebody standing in front of you.
 *
 * A walk-up is a large share of a club night, and until this existed the only
 * way to admit one was a comp: a free ticket, no money anywhere, and a guest
 * list that grew every hour for no recorded reason.
 *
 * Deliberately the same path as a checkout. The order is reserved and fulfilled
 * by the ordinary services, so a door sale takes stock the same way, mints the
 * same tickets, lands in the same reports and cannot oversell against an online
 * buyer reaching the last ticket at the same moment. What differs is written on
 * the order rather than implemented twice: the channel, how it was paid, and
 * who took it.
 *
 * The money never reaches us. It went into a tin, onto the venue's own card
 * terminal, or straight into a bank account — so the platform charges nothing
 * on it and the ledger takes it back out of what we owe.
 */
class DoorSales
{
    /** How somebody standing at a door actually pays. */
    public const METHODS = ['cash', 'card', 'transfer'];

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly Fulfiller $fulfiller,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Take the money, mint the tickets, and say what to do with them.
     *
     * @param  array<string, int>  $quantities  ticket type id => how many
     * @param  array<string, int>  $addOns  add-on id => how many
     */
    public function sell(
        Event $event,
        array $quantities,
        string $method,
        User $soldBy,
        ?string $doorPassId = null,
        ?string $buyerName = null,
        ?string $buyerEmail = null,
        array $addOns = [],
    ): Order {
        $order = $this->checkout->reserve(
            event: $event,
            quantities: $quantities,
            // Nobody has to give an address. The person paying cash is not
            // going to spell one out, and their ticket is scanned by the phone
            // that just sold it.
            buyerEmail: $buyerEmail,
            buyerName: $buyerName ?: 'Door sale',
            addOns: $addOns,
            channel: 'door',
            paymentMethod: $method,
            soldBy: $soldBy,
            doorPassId: $doorPassId,
        );

        // Paid the moment it is sold: the money is already in the tin, and
        // there is no webhook coming to tell us so.
        $order = $this->fulfiller->fulfil($order);

        $this->auditor->record('door.sold', $event, $soldBy, metadata: [
            'order' => $order->reference,
            'method' => $method,
            'total' => $order->total_amount,
            'currency' => $order->currency,
            'door_pass_id' => $doorPassId,
        ]);

        return $order;
    }

    /**
     * The till, as somebody counting it at 3am needs to read it.
     *
     * Per method, because that is what is being counted — a tin of cash, a
     * terminal's own total, a bank app's list of transfers — and each has to
     * agree with its own thing. Per till after that, because a night with two
     * doors has two tins and a manager reconciles them one at a time.
     *
     * @return array<string, mixed>
     */
    public function takings(Event $event): array
    {
        $rows = DB::table('orders')
            ->where('orders.event_id', $event->id)
            ->where('orders.channel', 'door')
            ->whereIn('orders.status', Code::PAID_STATUSES)
            ->leftJoin('door_passes', 'door_passes.id', '=', 'orders.door_pass_id')
            ->leftJoin('users', 'users.id', '=', 'orders.sold_by_user_id')
            ->groupBy('orders.payment_method', 'orders.door_pass_id', 'door_passes.label', 'users.name')
            ->selectRaw('orders.payment_method, orders.door_pass_id, door_passes.label, users.name as seller')
            ->selectRaw('count(*) as orders, sum(orders.total_amount) as total')
            ->get();

        $tickets = DB::table('tickets')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('orders.event_id', $event->id)
            ->where('orders.channel', 'door')
            ->whereIn('orders.status', Code::PAID_STATUSES)
            ->whereIn('tickets.status', ['valid', 'checked_in'])
            ->count();

        $byMethod = collect(self::METHODS)
            ->map(fn (string $method) => [
                'method' => $method,
                'orders' => (int) $rows->where('payment_method', $method)->sum('orders'),
                'total' => [
                    'amount' => (int) $rows->where('payment_method', $method)->sum('total'),
                    'currency' => $event->currency,
                ],
            ])
            // Only what was actually taken. A row of zeroes for a method
            // nobody used is one more thing to read at 3am.
            ->filter(fn (array $row) => $row['orders'] > 0)
            ->values()
            ->all();

        return [
            'currency' => $event->currency,
            'tickets' => $tickets,
            'total' => [
                'amount' => (int) $rows->sum('total'),
                'currency' => $event->currency,
            ],
            'by_method' => $byMethod,
            'by_till' => $rows
                ->groupBy(fn ($row) => $row->door_pass_id ?? 'organizer')
                ->map(fn ($group) => [
                    // A pass names the phone; without one it was the organizer
                    // selling from their own signed-in session.
                    'label' => $group->first()->label ?? ($group->first()->seller ?? 'Organizer'),
                    'orders' => (int) $group->sum('orders'),
                    'total' => ['amount' => (int) $group->sum('total'), 'currency' => $event->currency],
                ])
                ->values()
                ->all(),
        ];
    }
}
