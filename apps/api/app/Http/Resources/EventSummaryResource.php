<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
        $cheapest = $this->whenLoaded('ticketTypes', fn () => $this->ticketTypes
            ->where('status', 'on_sale')
            ->min('price_amount'));

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'starts_at' => $this->starts_at,
            'timezone' => $this->timezone,
            'city' => $this->city,
            'country' => $this->country,
            'category' => $this->category,
            'poster_url' => $this->poster_path
                ? Storage::disk('public')->url($this->poster_path)
                : null,
            'organizer' => [
                'name' => $this->whenLoaded('organization', fn () => $this->organization->name),
                'slug' => $this->whenLoaded('organization', fn () => $this->organization->slug),
            ],
            'from_price' => $cheapest !== null
                ? ['amount' => $cheapest, 'currency' => $this->currency]
                : null,
        ];
    }
}
