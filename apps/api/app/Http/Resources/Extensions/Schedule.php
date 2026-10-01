<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;

/**
 * When a night is set to go on sale by itself, in the console.
 *
 * Belongs to the scheduling feature, beside its other dates (OtherDates).
 * Null until it says otherwise: nothing is scheduled.
 */
class Schedule
{
    /** An ISO 8601 time, or null. */
    public function publishAt(Event $event): ?string
    {
        return null;
    }
}
