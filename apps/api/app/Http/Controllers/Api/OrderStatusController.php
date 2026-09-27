<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Events\CalendarFile;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Order status, by reference.
 *
 * What a client polls after returning from a payment page. The browser's
 * return proves nothing — the webhook decides — so the client asks here until
 * the status settles rather than assuming success because a redirect happened.
 *
 * Deliberately thin. It confirms whether payment landed and nothing else; the
 * tickets themselves come through the signed link, which is what a guest with
 * no account has.
 */
class OrderStatusController extends Controller
{
    public function __invoke(string $reference): JsonResponse
    {
        $order = Order::query()
            ->where('reference', strtoupper(trim($reference)))
            ->with('event:id,slug,title,starts_at,timezone')
            ->first();

        // References are 8 characters from a 27-letter alphabet, so guessing
        // one is impractical — but this returns no personal data regardless.
        if ($order === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        return response()->json([
            'reference' => $order->reference,
            'status' => $order->status,
            'total' => [
                'amount' => $order->total_amount,
                'currency' => $order->currency,
            ],
            'ticket_count' => $order->status === 'paid' ? $order->tickets()->count() : 0,
            'event' => [
                'slug' => $order->event->slug,
                'title' => $order->event->title,
                'starts_at' => $order->event->starts_at,
                'timezone' => $order->event->timezone,
                'calendar' => app(CalendarFile::class)->links($order->event),
            ],
        ]);
    }
}
