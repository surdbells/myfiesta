<?php

namespace App\Services\Events\Copying;

use App\Models\Event;

/**
 * What the features added since carry from a night to its copy.
 *
 * EventDuplicator names every column it copies, and it makes each date of a
 * series as well as a copy somebody asked for. So a column a feature adds to
 * events is not on the copy unless something says so — and whether it should
 * be is that feature's call, not the duplicator's: an opt-in to paying later
 * can simply carry over, while a friend discount means nothing on the copy
 * without the hidden code behind it, which codes never are. Each feature
 * answers in a class of its own here, so none of them edits EventDuplicator.
 *
 * Called inside the duplicator's transaction, once the copy, its tiers, its
 * banner and its reminders exist.
 */
class Carried
{
    public function __construct(
        private readonly PayLater $payLater,
        private readonly Share $share,
    ) {}

    public function carry(Event $source, Event $copy): void
    {
        $this->payLater->carry($source, $copy);
        $this->share->carry($source, $copy);
    }
}
