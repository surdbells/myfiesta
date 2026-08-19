<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The authoritative price.
 *
 * Checkout reads from here. No endpoint accepts a price from a client — that
 * single rule closes the defect that let buyers of the previous platform choose
 * what to pay.
 */
class TicketType extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sales_start_at' => 'datetime',
            'sales_end_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Currency comes from the event; a ticket type never carries its own. */
    protected function price(): Attribute
    {
        return Attribute::get(
            fn (): Money => new Money($this->price_amount, $this->event->currency)
        );
    }

    public function isFree(): bool
    {
        return $this->price_amount === 0;
    }
}
