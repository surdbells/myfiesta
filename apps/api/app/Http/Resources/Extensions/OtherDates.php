<?php

namespace App\Http\Resources\Extensions;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\Discovery\Availability;
use Illuminate\Http\Request;

/**
 * The other dates of a repeating night, on its event page: "More dates".
 *
 * Belongs to the scheduling feature. Null for a night that does not repeat;
 * an empty list for one whose other dates are all over, or not on sale yet.
 *
 * Only dates on sale and still to come, soonest first, and no more than a
 * page has room for: a weekly night has months of dates ahead, and the next
 * few are the ones somebody who cannot make this one is looking for. A draft
 * date is not anybody's business until it goes on sale. Each says how much
 * is left the way a card does (Availability), counted for all of them in one
 * query for their tiers.
 */
class OtherDates
{
    /** As many as the rail shows. */
    public const LIMIT = 8;

    public function __construct(private readonly Availability $availability) {}

    /** @return list<array{slug: string, starts_at: mixed, availability: mixed}>|null */
    public function forEvent(Event $event, Request $request): ?array
    {
        if ($event->series_id === null) {
            return null;
        }

        $scarcity = $this->availability->scarcity();

        return Event::query()
            ->where('series_id', $event->series_id)
            ->whereKeyNot($event->id)
            ->where('status', EventStatus::Published->value)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(self::LIMIT)
            ->with(['ticketTypes' => $this->availability->tiers()])
            ->get()
            ->map(fn (Event $date) => [
                'slug' => $date->slug,
                'starts_at' => $date->starts_at,
                'availability' => $this->availability->event($date)->toArray($scarcity),
            ])
            ->values()
            ->all();
    }
}
