<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A full event page.
 *
 * Extends the summary rather than restating it, so the two cannot drift — the
 * contract models it the same way, as an allOf over EventSummary.
 */
class EventResource extends EventSummaryResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'description' => $this->description,
            'ends_at' => $this->ends_at,
            'subdivision' => $this->subdivision,
            'dress_code' => $this->dress_code,
            'min_age' => $this->min_age,
            'id_required' => $this->id_required,

            // A name alone does not get anyone to the door.
            'venue' => $this->venue ? [
                'name' => $this->venue->name,
                'address' => $this->venue->address_line,
                'city' => $this->venue->city,
            ] : null,

            'organizer' => [
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
                'description' => $this->organization->description,
                'is_verified' => $this->organization->verified_at !== null,
                'logo_url' => $this->organization->logo_path
                    ? Storage::disk('public')->url($this->organization->logo_path)
                    : null,
            ],

            // Exactly 1200×630, which is what the social networks read. The
            // display rendition is a different shape and gets cropped by
            // whichever of them is doing the cropping, usually badly.
            'og_image_url' => $this->banner?->renditionUrl('og'),

            // Empty until after the night. A gallery is what sells the next
            // event to the people who missed this one.
            'gallery' => $this->gallery->map(fn ($image) => [
                'url' => $image->renditionUrl('display'),
                'thumb_url' => $image->renditionUrl('thumb'),
                'caption' => $image->caption,
                'width' => $image->width,
                'height' => $image->height,
            ])->values(),

            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
        ];
    }
}
