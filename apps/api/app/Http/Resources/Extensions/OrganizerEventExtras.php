<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use Illuminate\Http\Request;

/**
 * What the features added since put on an event as its organizer sees it
 * (Organizer\EventController::show, the console's OrganizerEventDetail).
 *
 * As EventExtras does for the public page: one field each, from a class of
 * the feature's own, always present, so no feature edits the controller to
 * add one. A console part that changes one of these through its own route
 * reads the event again afterwards (GET /organizer/events/{event}) and hands
 * that back, as the review steps on the Overview already do.
 */
class OrganizerEventExtras
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly PayLater $payLater,
        private readonly Share $share,
    ) {}

    /** @return array<string, mixed> */
    public function for(Event $event, Request $request): array
    {
        return [
            'publish_at' => $this->schedule->publishAt($event),
            'pay_later_enabled' => $this->payLater->forOrganizer($event),
            'share_offer' => $this->share->offerForOrganizer($event, $request),
        ];
    }
}
