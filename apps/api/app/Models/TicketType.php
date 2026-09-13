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

    /**
     * Locked: bought only with a code that unlocks it.
     *
     * Hidden tiers, and tiers whose sales have not opened yet — the two shapes
     * a presale takes. A code opens either early; nothing opens a tier whose
     * sales have ended, or one that is closed or sold out.
     */
    public function isLocked(): bool
    {
        return $this->status === 'hidden'
            || ($this->status === 'on_sale' && $this->sales_start_at !== null && $this->sales_start_at->isFuture());
    }

    public function salesEnded(): bool
    {
        return $this->sales_end_at !== null && $this->sales_end_at->isPast();
    }

    public function isFree(): bool
    {
        return $this->price_amount === 0;
    }
}
