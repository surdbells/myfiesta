<?php

namespace App\Http\Resources;

use App\Models\TicketType;
use App\Services\Discovery\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket type as a buyer sees it: on the event page, the ticket page and
 * what a presale code opens.
 *
 * The organizer's version (TicketTypeResource) carries the capacity, how many
 * sold and how many are left, because that screen exists to answer "how is
 * this one selling". None of that is a stranger's business: a public page
 * that says "312 left" out of a capacity of 500 is an organizer's sales,
 * readable by a rival refreshing it. Here there is a state, and an exact
 * count only once it is small enough to be the reason somebody decides now.
 *
 * @mixin TicketType
 */
class PublicTicketTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $availability = app(Availability::class);
        $tier = $availability->tier($this->resource);
        $shown = $tier->toArray($availability->scarcity());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => [
                'amount' => $this->price_amount,
                'currency' => $this->event->currency,
            ],
            'admits' => $this->admits,
            'max_per_order' => $this->max_per_order,
            'status' => $this->status,

            // Kept for the clients that read it before availability existed,
            // and now saying only what availability says: a number at or
            // under the setting, null otherwise — including when unlimited.
            'remaining' => $shown['left'],
            // Holds counted, as checkout counts them.
            'sold_out' => $tier->soldOut(),
            'availability' => $shown,

            // --- the price ladder -------------------------------------------
            'opens_after' => $this->opens_after_id && $this->opensAfter
                ? ['id' => $this->opensAfter->id, 'name' => $this->opensAfter->name]
                : null,
            'waiting' => $this->isWaiting(),

            // --- when it is on sale -----------------------------------------
            'sales_start_at' => $this->sales_start_at,
            'sales_end_at' => $this->sales_end_at,
        ];
    }
}
