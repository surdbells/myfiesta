<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Something sold with a ticket that is not one.
 *
 * Two bottles on a table, a cloakroom pass, a shirt. It has a price and a
 * stock and it goes on the same order — and it admits nobody, which is the
 * whole distinction. A Table of 6 is a ticket type because six people walk
 * through a door on it; the bottle on that table walks through nothing, so
 * buying one mints no ticket and the door never hears about it.
 */
class AddOn extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price_amount' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * How many are left, or null when there is no limit.
     *
     * Counted from what has been paid for plus what is held by a checkout in
     * progress — the same rule as a ticket type, arrived at differently. A
     * ticket type counts the tickets it has minted; an add-on mints nothing,
     * so its own sales are the count.
     *
     * A fully refunded order gives its stock back: it is no longer in
     * PAID_STATUSES, and the table it booked is free again.
     */
    public function remainingNow(): ?int
    {
        if ($this->quantity_available === null) {
            return null;
        }

        $sold = (int) DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.add_on_id', $this->id)
            ->whereIn('orders.status', Code::PAID_STATUSES)
            ->sum('order_lines.quantity');

        $held = (int) DB::table('inventory_holds')
            ->where('add_on_id', $this->id)
            ->where('expires_at', '>', now())
            ->sum('quantity');

        return max(0, $this->quantity_available - $sold - $held);
    }

    /** Nothing more to be had: closed, or every one gone. */
    public function isExhausted(): bool
    {
        return $this->status !== 'on_sale' || $this->remainingNow() === 0;
    }
}
