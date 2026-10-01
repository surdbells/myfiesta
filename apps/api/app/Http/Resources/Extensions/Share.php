<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use App\Models\Ticket;
use App\Services\Checkout\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Friend discounts: the offer a night makes, on its event page; the holder's
 * own link to share it, on the ticket; the discount a friend's link takes off,
 * on a quote; and the offer as its organizer set it, in the console.
 *
 * Belongs to the friend-discount feature. Null until it says otherwise,
 * which reads as no offer and no link.
 */
class Share
{
    /** @return array<string, mixed>|null */
    public function offerFor(Event $event, Request $request): ?array
    {
        return null;
    }

    /** @param  Collection<int, Ticket>  $tickets */
    public function primeTickets(Collection $tickets): void {}

    /** @return array<string, mixed>|null */
    public function linkFor(Ticket $ticket): ?array
    {
        return null;
    }

    /**
     * On a quote: the friend's discount, when a friend's link priced it.
     *
     * @return array<string, mixed>|null
     */
    public function forQuote(Quote $quote): ?array
    {
        return null;
    }

    /**
     * The code a quote names as applied. Here rather than in the quote,
     * because the one a friend's link applies is hidden: it is shown as the
     * friend's discount, never as a code somebody typed and can remove.
     */
    public function codeShown(Quote $quote): ?string
    {
        return $quote->code?->code;
    }

    /**
     * In the console: the offer as the organizer set it.
     *
     * @return array<string, mixed>|null
     */
    public function offerForOrganizer(Event $event, Request $request): ?array
    {
        return null;
    }
}
