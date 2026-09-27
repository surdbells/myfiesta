<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock reserved while a buyer is at the payment step.
 *
 * Taken in the same transaction that increments a code redemption count, so
 * neither tickets nor a capped code can oversell. Expired rows are swept and
 * the stock returns.
 *
 * Each belongs to the order it was taken for, and paying for that order gives
 * up its own holds and nobody else's. A hold with no order was taken before
 * holds said whose they were.
 */
class InventoryHold extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<TicketType, $this> */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
