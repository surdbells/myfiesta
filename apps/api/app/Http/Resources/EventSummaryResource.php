<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Services\Discovery\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event in a list.
 *
 * Carries the cheapest way in rather than a price range, because that is what a
 * browsing decision turns on and what the price filter matches.
 *
 * @mixin Event
 */
class EventSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Counted the way checkout counts (Availability). Every list loads its
        // tiers with the counts already on them, so this reads rather than
        // asks; an event handed over without them is loaded here, once.
        $availability = app(Availability::class);
        $selling = $availability->event($this->resource);

        // The cheapest tier still selling — the one the badge is about. Once
        // nothing is, the cheapest the organizer put on sale, so a sold-out
        // card still says what it cost.
        $cheapest = $selling->cheapest->price_amount
            ?? $this->ticketTypes->where('status', 'on_sale')->min('price_amount');

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'starts_at' => $this->starts_at,
            // So a card can tell a night that is over from one still to come
            // without asking again: the front page's sold-out shelf holds both.
            'ends_at' => $this->ends_at,
            'timezone' => $this->timezone,
            'city' => $this->city,
            'country' => $this->country,
            // Always present, even when nothing is on sale. Without it a client
            // showing "From —" has no symbol to render it with.
            'currency' => $this->currency,
            'category' => $this->category,
            // Kept under its original name because both generated clients and
            // the contract already carry it; what changed is where it comes
            // from. A list card gets the display rendition rather than the
            // stored original — the difference is roughly a megabyte per card
            // on a page that shows twenty of them.
            'poster_url' => $this->banner?->renditionUrl('display'),
            'organizer' => [
                'name' => $this->whenLoaded('organization', fn () => $this->organization->name),
                'slug' => $this->whenLoaded('organization', fn () => $this->organization->slug),
            ],
            'from_price' => $cheapest !== null
                ? ['amount' => (int) $cheapest, 'currency' => $this->currency]
                : null,
            // Every ticket a stranger can see has gone. A night whose sales
            // closed with places left is not sold out: its availability says
            // `closed`, and it offers no waitlist for tickets not coming back.
            'is_sold_out' => $selling->soldOut(),
            // "Almost sold out", "Sold out", "Sales closed", and an exact
            // count only when it is small enough for the admin's setting —
            // never an organizer's sales in a number anybody can read off a
            // card.
            'availability' => $selling->toArray($availability->scarcity()),
            // Whether a sold-out night still takes names for returned tickets:
            // the waitlist takes them until the night starts.
            'waitlist' => $selling->soldOut() && $this->starts_at?->isFuture() === true,
        ];
    }
}
