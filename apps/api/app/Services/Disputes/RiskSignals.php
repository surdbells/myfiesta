<?php

namespace App\Services\Disputes;

use App\Models\EmailPreference;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The few things worth saying about an order before it goes wrong.
 *
 * Deliberately small, and deliberately made of facts rather than a score. A
 * number between 0 and 100 invites an organizer to refuse somebody on a
 * hunch the platform manufactured; "this address had a chargeback in June" is
 * something they can check, argue with, and act on.
 *
 * What is not here, on purpose: anything about the card — the processor's
 * record of it is kept to answer a bank (ProcessorEvidence), not to judge the
 * next buyer — and anything about a device or a location, which would mean
 * building the tracking the rest of this platform refuses to do. The address
 * and browser an order came from are kept for the same bank, and read by
 * nothing here.
 */
class RiskSignals
{
    /** More orders than this from one address in a day is worth mentioning. */
    public const BURST = 4;

    public const BURST_HOURS = 24;

    /**
     * Signals for a batch of orders, keyed by order id.
     *
     * In two queries rather than two per order: this runs over a page of an
     * organizer's order list, and an n+1 there is a slow screen on the busiest
     * night of their year.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<string, list<string>>
     */
    public function forOrders($orders): array
    {
        $emails = $orders
            ->pluck('buyer_email')
            ->filter()
            ->map(fn (string $email) => EmailPreference::normalise($email))
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return [];
        }

        $chargedBack = DB::table('disputes')
            ->join('orders', 'orders.id', '=', 'disputes.order_id')
            ->where('disputes.status', 'lost')
            ->whereIn(DB::raw('lower(orders.buyer_email)'), $emails->all())
            ->selectRaw('lower(orders.buyer_email) as email')
            ->pluck('email')
            ->flip();

        // Many orders in a day from one address. Usually a promoter buying for
        // a group; occasionally somebody working through stolen cards, which
        // is why it is said rather than acted on.
        $busy = DB::table('orders')
            ->whereIn(DB::raw('lower(buyer_email)'), $emails->all())
            ->where('created_at', '>=', now()->subHours(self::BURST_HOURS))
            ->groupBy(DB::raw('lower(buyer_email)'))
            ->havingRaw('count(*) > ?', [self::BURST])
            ->selectRaw('lower(buyer_email) as email')
            ->pluck('email')
            ->flip();

        $signals = [];

        foreach ($orders as $order) {
            $email = $order->buyer_email ? EmailPreference::normalise($order->buyer_email) : null;
            $flags = [];

            if ($email !== null && $chargedBack->has($email)) {
                $flags[] = 'previous_chargeback';
            }

            if ($email !== null && $busy->has($email)) {
                $flags[] = 'many_orders';
            }

            if ($order->disputed_at !== null) {
                $flags[] = 'disputed';
            }

            if ($flags !== []) {
                $signals[$order->id] = $flags;
            }
        }

        return $signals;
    }
}
