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
        ];
    }
}
