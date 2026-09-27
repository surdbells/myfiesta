<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLine extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Set on a ticket line, null on an add-on. Exactly one of the two is.
     *
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /** @return BelongsTo<AddOn, $this> */
    public function addOn(): BelongsTo
    {
        // Including removed ones: an order line points at what was sold, and
        // an organizer taking a bottle off the list does not unsell it.
        return $this->belongsTo(AddOn::class)->withTrashed();
    }

    /** Whether this line is a thing somebody walks through a door on. */
    public function isTicket(): bool
    {
        return $this->ticket_type_id !== null;
    }

    /**
     * Snapshotted: the ticket type may be renamed or repriced afterwards.
     *
     * @return Attribute<Money, never>
     */
    protected function unitPrice(): Attribute
    {
        return Attribute::get(fn () => new Money($this->unit_price_amount, $this->order->currency));
    }

    /** @return Attribute<Money, never> */
    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => new Money($this->line_total_amount, $this->order->currency));
    }
}
