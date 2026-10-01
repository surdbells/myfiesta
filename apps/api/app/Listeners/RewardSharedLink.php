<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Sharing\ShareRewards;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A friend paid through somebody's link: that somebody is sent their reward.
 *
 * Once per friend's order, however often the payment is announced, and only
 * under the link's cap (ShareRewards).
 */
class RewardSharedLink implements ShouldQueue
{
    public function __construct(private readonly ShareRewards $rewards) {}

    public function handle(OrderPaid $paid): void
    {
        $order = $paid->order->fresh();

        if ($order !== null) {
            $this->rewards->rewardFor($order);
        }
    }
}
