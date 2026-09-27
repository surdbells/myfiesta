<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TicketActivity;
use App\Services\Disputes\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One order's tickets, reached by a signed link.
 *
 * The signature is the authorisation. There is no account to check because
 * guest checkout is the primary path — and no id to guess, because order ids
 * are UUIDs and the link expires.
 *
 * Opening it goes into the order's ticket history, like the ticket page.
 */
class OrderController extends Controller
{
    public function __invoke(Request $request, Order $order): JsonResponse
    {
        app(ActivityLog::class)->opened($order, TicketActivity::ORDER_LINK, $request);

        $order->load(['event.venue', 'tickets.ticketType']);

        return response()->json([
            'reference' => $order->reference,
            'status' => $order->status,
            'event' => [
                'title' => $order->event->title,
                'starts_at' => $order->event->starts_at,
                'timezone' => $order->event->timezone,
                'venue' => $order->event->venue?->name ?? $order->event->city,
            ],
            'tickets' => $order->tickets->map(fn ($t) => [
                'code' => $t->code,
                'type' => $t->ticketType->name,
                'holder' => $t->holder_name,
                'status' => $t->status,
            ]),
        ]);
    }
}
