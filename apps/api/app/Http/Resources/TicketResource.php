<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'holder_name' => $this->holder_name,
            'checked_in_at' => $this->checked_in_at,
            'type' => $this->whenLoaded('ticketType', fn () => $this->ticketType->name),
            'event' => $this->whenLoaded('event', fn () => [
                'slug' => $this->event->slug,
                'title' => $this->event->title,
                'starts_at' => $this->event->starts_at,
                'timezone' => $this->event->timezone,
                'city' => $this->event->city,
            ]),
        ];
    }
}
