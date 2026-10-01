<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;

/**
 * When a night is set to go on sale by itself, in the console.
 *
 * Belongs to the scheduling feature, beside its other dates (OtherDates).
 * Null when nothing is scheduled. Once the night goes on sale, or is sent
 * for review at its time, the time is spent and this is null again
 * (EventReviews::goOnSale, ScheduledGoLive).
 */
class Schedule
{
    /** An ISO 8601 time, or null. */
    public function publishAt(Event $event): ?string
    {
        return $event->publish_at?->toIso8601String();
    }
}
