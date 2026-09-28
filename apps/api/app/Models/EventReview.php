<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an event's review: sent, approved, sent back, or taken back.
 *
 * Written once and never changed. It is what the organizer is shown as the
 * event's review history, what the reviewer's reason is read back from, and —
 * through the snapshot kept on submissions and approvals — what "changed since
 * it was last approved" is measured against.
 */
class EventReview extends Model
{
    use HasUuids;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    /** Written once. Laravel would otherwise look for a column that is absent. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
