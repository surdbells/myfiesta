<?php

namespace App\Listeners;

use App\Events\OrderFullyRefunded;
use App\Services\Sharing\ShareRewards;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A friend's order refunded in full takes back the reward it earned, while
 * that reward is unspent. Buying through a link and refunding straight away
 * should not leave a free code behind.
 */
class VoidShareReward implements ShouldQueue
{
    public function __construct(private readonly ShareRewards $rewards) {}

    public function handle(OrderFullyRefunded $refunded): void
    {
        $this->rewards->voidFor($refunded->order);
    }
}
