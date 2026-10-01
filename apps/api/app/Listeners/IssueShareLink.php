<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Sharing\ShareLinks;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A paid order's buyer gets their friend's link, when the night offers one.
 *
 * Queued, as every listener on OrderPaid is (SaidOnceCommitted), so the
 * tickets email may be written before this has run. It asks ShareLinks for
 * the link too, which makes it then, and both find the same one.
 */
class IssueShareLink implements ShouldQueue
{
    public function __construct(private readonly ShareLinks $links) {}

    public function handle(OrderPaid $paid): void
    {
        $order = $paid->order->fresh(['event']);

        if ($order !== null) {
            $this->links->forOrder($order);
        }
    }
}
