<?php

namespace App\Http\Resources\Extensions;

use App\Models\Event;
use Illuminate\Http\Request;

/**
 * What the features added since put on an event page.
 *
 * One field each, answered by a class of the feature's own, so a feature
 * fills its field in its own file and never edits EventResource or another
 * feature's. Every field is always present, as null, false or an empty list
 * when there is nothing to say: the contract declares them, and a client must
 * never mistake "nothing" for "an older server" (ContractConformanceTest).
 *
 * The page is one event, so each may ask the database once; none may ask
 * once per ticket type or per date.
 */
class EventExtras
{
    public function __construct(
        private readonly OtherDates $otherDates,
        private readonly Perks $perks,
        private readonly Share $share,
        private readonly NotifyOnSale $notifyOnSale,
        private readonly PayLater $payLater,
    ) {}

    /** @return array<string, mixed> */
    public function for(Event $event, Request $request): array
    {
        return [
            'other_dates' => $this->otherDates->forEvent($event, $request),
            'perks' => $this->perks->forEvent($event, $request),
            'share_offer' => $this->share->offerFor($event, $request),
            'notify_on_sale' => $this->notifyOnSale->forEvent($event, $request),
            'pay_later' => $this->payLater->forEvent($event, $request),
        ];
    }
}
