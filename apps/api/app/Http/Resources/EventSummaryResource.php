<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * An event in a list.
 *
 * Carries the cheapest way in rather than a price range, because that is what a
 * browsing decision turns on and what the price filter matches.
 */
class EventSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $onSale = $this->whenLoaded('ticketTypes',
            fn () => $this->ticketTypes->where('status', 'on_sale'));

        $cheapest = $onSale instanceof Collection
            ? $onSale->min('price_amount')
            : null;

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'starts_at' => $this->starts_at,
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
                ? ['amount' => $cheapest, 'currency' => $this->currency]
                : null,
            // Nothing on sale means nothing left to buy, whether the tickets
            // sold out or the organizer closed them.
            'is_sold_out' => $onSale instanceof Collection
                ? $onSale->isEmpty()
                : false,
        ];
    }
}
