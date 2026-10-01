<?php

namespace App\Services\Events\Copying;

use App\Models\Event;
use App\Services\Sharing\ShareOffers;

/**
 * A night's friend discount on its copy: the offer, and the hidden code a
 * friend's link resolves to, made again for the copy — or neither.
 *
 * The code is made afresh rather than pointed at: the original's is scoped to
 * the original, and a copy whose offer resolved to it would discount nothing.
 * The links people hold are the original's and stay there; buyers of the copy
 * are given their own.
 */
class Share
{
    public function __construct(private readonly ShareOffers $offers) {}

    public function carry(Event $source, Event $copy): void
    {
        if (! ShareOffers::offered($source)) {
            return;
        }

        $copy->forceFill([
            'share_discount_bps' => (int) $source->share_discount_bps,
            'share_max_rewards' => (int) $source->share_max_rewards,
        ])->save();

        $this->offers->syncCode($copy);
    }
}
