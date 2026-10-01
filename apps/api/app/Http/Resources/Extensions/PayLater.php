<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use App\Services\Checkout\Quote;
use App\Services\Payments\PayLater as Offer;
use Illuminate\Http\Request;

/**
 * Whether a night can be paid for later, with Klarna or Affirm, and through
 * which: on its event page, on a quote, and as the organizer's own opt-in in
 * the console.
 *
 * The answers are PayLater's (App\Services\Payments); this only puts them
 * where each response carries them. Null or false reads as not offered.
 */
class PayLater
{
    public function __construct(private readonly Offer $offer) {}

    /**
     * On the event page: who lends for this night (`{providers}`), or null
     * when nobody can pay for it later.
     *
     * @return array{providers: list<string>}|null
     */
    public function forEvent(Event $event, Request $request): ?array
    {
        return $this->offer->forEvent($event);
    }

    /**
     * On a quote: whether this basket may be paid later, and with whom
     * (`{eligible, providers}`). Null reads as not offered.
     *
     * @return array{eligible: bool, providers: list<string>}|null
     */
    public function forQuote(Quote $quote): ?array
    {
        return $this->offer->forQuote($quote);
    }

    /** In the console: whether the organizer has opted this night in. */
    public function forOrganizer(Event $event): bool
    {
        return (bool) $event->pay_later_enabled;
    }
}
