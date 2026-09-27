<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ticket its holder has given back, waiting for somebody to take the place.
 *
 * Carries what the seller paid rather than what the place now costs: the
 * organizer may have moved the price since, and what is owed to somebody
 * returning a ticket is what they handed over.
 */
class ResaleListing extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'listed_at' => UtcDateTime::class,
            'sold_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isListed(): bool
    {
        return $this->status === 'listed';
    }
}
