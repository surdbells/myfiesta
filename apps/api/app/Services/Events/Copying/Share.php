<?php

namespace App\Services\Events\Copying;

use App\Models\Event;

/**
 * A night's friend discount on its copy: the offer, and the hidden code a
 * friend's link resolves to, made again for the copy — or neither.
 *
 * Belongs to the friend-discount feature. Nothing to carry until it adds
 * its offer to events.
 */
class Share
{
    public function carry(Event $source, Event $copy): void {}
}
