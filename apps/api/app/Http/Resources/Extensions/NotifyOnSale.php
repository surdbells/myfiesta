<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use Illuminate\Http\Request;

/**
 * Whether the event page offers "Tell me when tickets go on sale": a night
 * that is on, still to come and not sold out, with nothing on sale yet.
 *
 * Belongs to the waitlist feature. False until it says otherwise.
 */
class NotifyOnSale
{
    public function forEvent(Event $event, Request $request): bool
    {
        return false;
    }
}
