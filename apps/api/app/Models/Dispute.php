<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer told their bank the charge was wrong.
 *
 * Kept whatever the outcome. A dispute that was won is the evidence that the
 * organizer answered it, and a buyer with one behind them is the single most
 * useful thing to know about the next order they place.
 */
class Dispute extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => UtcDateTime::class,
            'evidence_due_at' => UtcDateTime::class,
            'closed_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
