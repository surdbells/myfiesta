<?php

namespace App\Services\Events\Copying;

use App\Models\Event;

/**
 * Whether a copy may be paid for later, as its original could.
 *
 * The organizer's opt-in carries over as it is: they agreed to pay the
 * lenders' fee on the night, and a copy or the next date of a series is the
 * same night again. Whether a buyer is offered it still depends on myFiesta
 * having it on and the night being near enough (PayLater::offeredFor), which
 * the copy is asked afresh.
 */
class PayLater
{
    public function carry(Event $source, Event $copy): void
    {
        if ((bool) $source->pay_later_enabled === (bool) $copy->pay_later_enabled) {
            return;
        }

        $copy->forceFill(['pay_later_enabled' => (bool) $source->pay_later_enabled])->save();
    }
}
