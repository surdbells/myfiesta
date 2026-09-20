<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An organizer writing to a list they chose, usually about a night to come.
 *
 * Kept after sending with who it reached and what it sold, because "did the
 * email work?" deserves a number rather than a feeling.
 */
class Campaign extends Model
{
    use HasUuids;

    public const AUDIENCES = ['followers', 'past_attendees', 'abandoned'];

    /**
     * Every state, in the order a campaign moves through them.
     *
     * Written down because the filter offers them and the console labels
     * them, and a list of statuses that lives only in a validation rule and
     * a TypeScript union is two lists that will disagree.
     */
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];

    /** Editable, and cancellable, until the sending starts. */
    public const OPEN = ['draft', 'scheduled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => UtcDateTime::class,
            'sent_at' => UtcDateTime::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            // Short, and marked as coming from an email, so an organizer
            // reading their orders' refs can tell what it was.
            $campaign->ref ??= 'email-'.Str::lower(Str::random(8));
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
