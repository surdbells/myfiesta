<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // Always an amount with its currency. There is no endpoint in this
            // API that returns a bare number and leaves the client guessing.
            'price' => [
                'amount' => $this->price_amount,
                'currency' => $this->event->currency,
            ],
            'admits' => $this->admits,
            'max_per_order' => $this->max_per_order,
            'status' => $this->status,

            // --- inventory ---------------------------------------------------
            //
            // Null means unlimited, and it is not the same as zero. A console
            // that renders a missing capacity as 0 tells an organizer their
            // guest list is sold out.
            'quantity_available' => $this->quantity_available,

            /*
             * How many exist, and how many are left.
             *
             * Counted here rather than left to the console, which cannot do it:
             * it would need every ticket on the event to count them per type,
             * on a screen whose entire purpose is answering "how is this one
             * selling". `withCount` on the query keeps it to one extra query
             * for the whole collection.
             */
            'sold' => (int) ($this->issued_count ?? 0),
            'remaining' => $this->quantity_available === null
                ? null
                : max(0, $this->quantity_available - (int) ($this->issued_count ?? 0)),

            /*
             * Whether anything is left to buy now, for the public page.
             *
             * The stored status is never set to sold_out — it is a count, not
             * a state — so a tier that had sold every place still looked on
             * sale to buyers until checkout refused them.
             */
            'sold_out' => $this->quantity_available !== null && $this->remainingNow() === 0,

            // --- the price ladder -------------------------------------------
            'opens_after' => $this->opens_after_id && $this->opensAfter
                ? ['id' => $this->opensAfter->id, 'name' => $this->opensAfter->name]
                : null,
            // Waiting for that tier to sell out; not on sale until it does.
            'waiting' => $this->isWaiting(),

            // --- when it is on sale -----------------------------------------
            'sales_start_at' => $this->sales_start_at,
            'sales_end_at' => $this->sales_end_at,
        ];
    }
}
