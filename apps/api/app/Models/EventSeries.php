<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The definition behind a repeating event.
 *
 * Holds the rule and nothing else about the event itself — the shape lives on
 * the source event, which is a real one the organizer can look at rather than a
 * hidden template where a wrong price could sit unnoticed.
 */
class EventSeries extends Model
{
    use HasUuids;

    protected $table = 'event_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => UtcDateTime::class,
            'generated_through' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The event occurrences are copied from.
     *
     * @return BelongsTo<Event, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'source_event_id');
    }

    /** @return HasMany<Event, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Event::class, 'series_id')->orderBy('series_occurs_at');
    }

    /** @return HasMany<EventSeriesException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(EventSeriesException::class, 'series_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
