<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A buyer told their bank the charge was wrong.
 *
 * Kept whatever the outcome. A dispute that was won is the evidence that the
 * organizer answered it, and a buyer with one behind them is the single most
 * useful thing to know about the next order they place.
 *
 * The answer to it is beside it (DisputeEvidence); whether it was answered,
 * and how, is here, because that outlives the evidence itself.
 */
class Dispute extends Model
{
    use HasUuids;

    /** The evidence was sent to the processor for the bank to decide. */
    public const SUBMITTED = 'submitted';

    /** The buyer was right, and we said so rather than contest it. */
    public const ACCEPTED = 'accepted';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => UtcDateTime::class,
            'evidence_due_at' => UtcDateTime::class,
            'closed_at' => UtcDateTime::class,
            'processor_checked_at' => UtcDateTime::class,
            'responded_at' => UtcDateTime::class,
            'staff_told_at' => UtcDateTime::class,
            'reminded_five_days_at' => UtcDateTime::class,
            'reminded_two_days_at' => UtcDateTime::class,
        ];
    }

    /** @return HasOne<DisputeEvidence, $this> */
    public function evidence(): HasOne
    {
        return $this->hasOne(DisputeEvidence::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /** Whether the evidence was sent, or the dispute conceded. Either is final. */
    public function isAnswered(): bool
    {
        return $this->response !== null;
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
