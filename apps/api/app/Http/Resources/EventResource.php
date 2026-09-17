<?php

namespace App\Http\Resources;

use App\Services\Events\CalendarFile;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A full event page.
 *
 * Extends the summary rather than restating it, so the two cannot drift — the
 * contract models it the same way, as an allOf over EventSummary.
 */
class EventResource extends EventSummaryResource
{
    /**
     * The page is public, so a reader may or may not be signed in.
     *
     * The token is read through the sanctum guard by hand rather than by
     * middleware: requiring one here would shut guests out of an event page,
     * and guest checkout is the primary way people buy.
     */
    private function savedByReader(Request $request): bool
    {
        $user = $request->user('sanctum');

        if ($user === null) {
            return false;
        }

        return DB::table('saved_events')
            ->where('user_id', $user->id)
            ->where('event_id', $this->id)
            ->exists();
    }

    private function followsOrganizer(Request $request): bool
    {
        $user = $request->user('sanctum');

        if ($user === null) {
            return false;
        }

        return DB::table('organization_follows')
            ->where('user_id', $user->id)
            ->where('organization_id', $this->organization->id)
            ->exists();
    }

    public function toArray(Request $request): array
    {
        // array_replace, not +: with + the left operand wins, so every key
        // the page redefines — the organizer, with its description, its badge
        // and its logo — silently lost to the summary's two-field version.
        return array_replace(parent::toArray($request), [
            // Sanitized HTML, safe to render. The model cleans it on the way in,
            // so this is a read, not a second sanitizing pass.
            'description' => $this->description,
            // For every place that cannot render markup: meta tags, link
            // previews, structured data. HTML there shows the tags to readers.
            'description_text' => RichText::toText($this->description),
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
                'is_verified' => $this->organization->isVerified(),
                'logo_url' => $this->organization->logo_path
                    ? Storage::disk('public')->url($this->organization->logo_path)
                    : null,
                // Whether *this* reader follows them. Never how many do: a
                // follower count is a number an organizer would start managing
                // instead of running nights.
                'following' => $this->followsOrganizer($request),
            ],

            // Whether this reader saved it. There is no count — saving is a
            // private list, not applause.
            'saved' => $this->savedByReader($request),

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

            // "Add to calendar": a file for Apple and Outlook, a link for Google.
            'calendar' => app(CalendarFile::class)->links($this->resource),

            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
        ]);
    }
}
