<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TicketActivity;
use App\Services\Disputes\ActivityLog;
use App\Services\Events\CalendarFile;
use App\Services\Tickets\TicketAccessPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A buyer's tickets, reached by the link in their email.
 *
 * No authentication, because guest checkout is the primary path and most people
 * holding a ticket have no account. The token in the URL is the whole
 * credential — it is random, tied to one order, and confirms nothing about
 * whoever holds it.
 *
 * The previous platform served this at /tickets/{sale_id} with no signature at
 * all, so anyone could read anyone else's tickets by counting upwards.
 *
 * Each opening goes into the order's ticket history (ActivityLog): "I never
 * got my tickets" is answered by the link in the email having been opened.
 */
class TicketAccessController extends Controller
{
    public function __construct(private readonly TicketAccessPayload $payload) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $order = $this->order($token);

        app(ActivityLog::class)->opened($order, TicketActivity::TICKET_PAGE, $request);

        return response()->json($this->payload->for($order));
    }

    /**
     * The night as a calendar file, from the ticket page.
     *
     * The same file as the event's own (EventController::calendar), fetched
     * through the order so that adding the night to a calendar is in the
     * ticket history too — somebody who put it in their diary knew the date.
     */
    public function calendar(Request $request, string $token, CalendarFile $calendar): Response
    {
        $order = $this->order($token);
        $event = $order->event()->with('venue')->firstOrFail();

        app(ActivityLog::class)->opened($order, TicketActivity::CALENDAR, $request);

        return response($calendar->for($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$calendar->filename($event).'"',
            // Behind somebody's own link, so no shared cache keeps it.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function order(string $token): Order
    {
        $order = Order::where('access_token', $token)->first();

        // 404 rather than 403. A different answer for a real token with a typo
        // and an invented one would let somebody test tokens by the response.
        if ($order === null) {
            throw new NotFoundHttpException('No tickets found for that link.');
        }

        return $order;
    }
}
