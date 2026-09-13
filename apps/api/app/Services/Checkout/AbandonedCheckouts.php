<?php

namespace App\Services\Checkout;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Closing checkouts nobody finished.
 *
 * An order is opened as pending when somebody heads to payment, and nothing
 * ever closed one that was abandoned. Stripe says when its page expires;
 * Paystack never says anything, and a buyer who hit a gateway error never
 * reached either. So every walked-away basket sat in the organizer's orders
 * as "Confirming" indefinitely — in Lagos, most of the list.
 *
 * Closed as cancelled, which the buyer's order page already explains as
 * "nothing has been charged". A payment that does land later is still
 * honoured: fulfilment does not look at the old status, because refusing
 * tickets somebody has paid for is worse than a tidy list.
 */
class AbandonedCheckouts
{
    /**
     * Long enough past any payment page's own expiry — Stripe's is set to
     * thirty minutes — for a slow bank redirect or a delayed webhook to land
     * before the order is called abandoned.
     */
    public const AFTER_MINUTES = 120;

    /** @return int how many orders were closed */
    public function expire(): int
    {
        $closed = Order::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(self::AFTER_MINUTES))
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        // Holds stop counting against stock the moment they expire; the rows
        // are only kept a day, for reading an on-sale afterwards.
        DB::table('inventory_holds')->where('expires_at', '<', now()->subDay())->delete();

        return $closed;
    }
}
