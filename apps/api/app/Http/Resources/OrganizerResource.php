<?php

namespace App\Http\Resources;

use App\Models\Organization;
use App\Services\Organizations\Socials;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * An organizer's own page.
 *
 * The same brand block an event page carries — name, mark, words, tick — with
 * the nights attached. Deliberately not a profile: there is no follower count,
 * no join date and no contact address, because none of those help somebody
 * decide whether to go to a party and two of them belong to the organizer
 * rather than to the public.
 *
 * @mixin Organization
 */
class OrganizerResource extends JsonResource
{
    /**
     * @param  Collection  $upcoming
     * @param  Collection  $past
     * @param  bool  $morePast  Whether there are older nights than $past to page on to.
     */
    public function __construct($resource, private $upcoming, private $past, private bool $morePast = false)
    {
        parent::__construct($resource);
    }

    /**
     * Whether the reader follows them.
     *
     * Read through the sanctum guard by hand, as on the event page: the page
     * is public, so most readers have no token at all, and requiring one would
     * shut every stranger out of the link an organizer shares.
     */
    private function followedByReader(Request $request): bool
    {
        $user = $request->user('sanctum');

        if ($user === null) {
            return false;
        }

        return DB::table('organization_follows')
            ->where('user_id', $user->id)
            ->where('organization_id', $this->id)
            ->exists();
    }

    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'is_verified' => $this->isVerified(),
            'logo_url' => $this->logo_path
                ? Storage::disk('public')->url($this->logo_path)
                : null,
            // Whether *this* reader follows them, never how many do.
            'following' => $this->followedByReader($request),

            'upcoming' => EventSummaryResource::collection($this->upcoming),
            // What they have already run. For a stranger deciding whether to
            // trust a name on an Instagram post, a list of nights that
            // happened is worth more than anything the organizer writes about
            // themselves.
            'past' => EventSummaryResource::collection($this->past),
            // Whether "Show more past events" has anything to show, from
            // GET /organizers/{slug}/events?when=past&page=2 on.
            'past_has_more' => $this->morePast,
            // Where else to find them, as links built here from the part that
            // names the account (Socials), so a value in the database never
            // decides on its own where a reader is sent. Whatever cannot be
            // read is left off.
            'socials' => Socials::links($this->resource),
        ];
    }
}
