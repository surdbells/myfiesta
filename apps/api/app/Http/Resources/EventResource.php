<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'timezone' => $this->timezone,
            'city' => $this->city,
            'subdivision' => $this->subdivision,
            'country' => $this->country,
            'currency' => $this->currency,
            'category' => $this->category,
            'dress_code' => $this->dress_code,
            'min_age' => $this->min_age,
            'id_required' => $this->id_required,
            'poster_url' => $this->poster_path
                ? Storage::disk('public')->url($this->poster_path)
                : null,
            'venue' => $this->whenLoaded('venue', fn () => [
                'name' => $this->venue?->name,
                'address' => $this->venue?->address_line,
                'city' => $this->venue?->city,
            ]),
            'organizer' => $this->whenLoaded('organization', fn () => [
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
                'description' => $this->organization->description,
                'verified' => $this->organization->verified_at !== null,
                'logo_url' => $this->organization->logo_path
                    ? Storage::disk('public')->url($this->organization->logo_path)
                    : null,
            ]),
            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
        ];
    }
}
