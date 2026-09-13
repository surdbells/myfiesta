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
use Illuminate\Support\Facades\DB;

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

    /** The tier this one waits for: it opens when that one sells out. */
    public function opensAfter(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'opens_after_id');
    }

    /**
     * Places left to buy right now: capacity, less tickets issued, less
     * checkouts in progress. Null when the tier is unlimited.
     */
    public function remainingNow(): ?int
    {
        if ($this->quantity_available === null) {
            return null;
        }

        $issued = DB::table('tickets')->where('ticket_type_id', $this->id)->whereIn('status', ['valid', 'checked_in'])->count();
        $held = (int) DB::table('inventory_holds')->where('ticket_type_id', $this->id)->where('expires_at', '>', now())->sum('quantity');

        return max(0, $this->quantity_available - $issued - $held);
    }

    /** Nothing more to be had from it: closed, ended, or every place gone. */
    public function isExhausted(): bool
    {
        return in_array($this->status, ['closed', 'sold_out'], true)
            || $this->salesEnded()
            || $this->remainingNow() === 0;
    }

    /**
     * Still waiting for the tier before it to sell out.
     *
     * Counted with checkouts in progress, so the next tier opens as the last
     * Early Bird goes into somebody's basket rather than after they pay — and
     * a basket abandoned puts those places back, briefly opening both.
     */
    public function isWaiting(): bool
    {
        return $this->opens_after_id !== null
            && $this->opensAfter !== null
            && ! $this->opensAfter->isExhausted();
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
            || ($this->status === 'on_sale' && $this->sales_start_at !== null && $this->sales_start_at->isFuture())
            || $this->isWaiting();
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
