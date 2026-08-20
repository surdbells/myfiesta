<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled reminder for one event.
 *
 * When it goes out is derived from the event's start rather than stored, so
 * moving an event moves its reminders with it. An organizer who pushes a date
 * back a week and then finds the "tomorrow" email already went is a support
 * conversation nobody recovers from gracefully.
 */
class EventReminder extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sendAt(): CarbonInterface
    {
        return $this->event->starts_at->copy()->subMinutes($this->offset_minutes);
    }

    /**
     * How this reads to somebody who bought a ticket.
     *
     * Rounded to the unit a person would actually say. "Starts in 10080
     * minutes" is accurate and nobody talks like that.
     */
    public function describe(): string
    {
        $minutes = $this->offset_minutes;

        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days === 1 ? '1 day before' : "{$days} days before";
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour before' : "{$hours} hours before";
        }

        return "{$minutes} minutes before";
    }
}
