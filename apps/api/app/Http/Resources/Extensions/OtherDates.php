<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use Illuminate\Http\Request;

/**
 * The other dates of a repeating night, on its event page: "More dates".
 *
 * Belongs to the scheduling feature. Null until it says otherwise, which
 * reads as a night with no other dates.
 */
class OtherDates
{
    /** @return list<array{slug: string, starts_at: mixed, availability: mixed}>|null */
    public function forEvent(Event $event, Request $request): ?array
    {
        return null;
    }
}
