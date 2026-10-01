<?php

namespace App\Services\Events\Copying;

use App\Models\Event;

/**
 * Whether a copy may be paid for later, as its original could.
 *
 * Belongs to the pay-later feature. Nothing to carry until it adds its
 * opt-in to events.
 */
class PayLater
{
    public function carry(Event $source, Event $copy): void {}
}
