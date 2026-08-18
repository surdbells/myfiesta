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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /** Snapshotted: the ticket type may be renamed or repriced afterwards. */
    protected function unitPrice(): Attribute
    {
        return Attribute::get(fn () => new Money($this->unit_price_amount, $this->order->currency));
    }

    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => new Money($this->line_total_amount, $this->order->currency));
    }
}
