<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That a night took place, and how its door went, written down once it was
 * over (EventCompletions). Never changed and never deleted: the database
 * refuses both.
 */
class EventCompletion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => UtcDateTime::class,
            'ends_at' => UtcDateTime::class,
            'door_opened_at' => UtcDateTime::class,
            'door_closed_at' => UtcDateTime::class,
            'recorded_at' => UtcDateTime::class,
            'tickets_issued' => 'integer',
            'tickets_live' => 'integer',
            'people_admitted' => 'integer',
            'turned_away' => 'integer',
            'scans' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
