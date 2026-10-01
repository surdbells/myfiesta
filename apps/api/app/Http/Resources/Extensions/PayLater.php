<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use App\Services\Checkout\Quote;
use Illuminate\Http\Request;

/**
 * Whether a night can be paid for later, with Klarna or Affirm, and through
 * which: on its event page, on a quote, and as the organizer's own opt-in in
 * the console.
 *
 * Belongs to the pay-later feature. Null or false until it says otherwise,
 * which reads as not offered.
 */
class PayLater
{
    /** @return array<string, mixed>|null */
    public function forEvent(Event $event, Request $request): ?array
    {
        return null;
    }

    /**
     * On a quote: whether this basket may be paid later, and with whom
     * (`{eligible, providers}`). Null reads as not offered.
     *
     * @return array<string, mixed>|null
     */
    public function forQuote(Quote $quote): ?array
    {
        return null;
    }

    /** In the console: whether the organizer has opted this night in. */
    public function forOrganizer(Event $event): bool
    {
        return false;
    }
}
