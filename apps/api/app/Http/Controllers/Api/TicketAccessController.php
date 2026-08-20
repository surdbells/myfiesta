<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Tickets\QrEncoder;
use Illuminate\Http\JsonResponse;
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
 */
class TicketAccessController extends Controller
{
    public function __construct(private readonly QrEncoder $qr) {}

    public function __invoke(string $token, QrEncoder $qr): JsonResponse
    {
        $order = Order::with(['event.venue', 'event.organization', 'tickets.ticketType'])
            ->where('access_token', $token)
            ->first();

        // 404 rather than 403. A different answer for a real token with a typo
        // and an invented one would let somebody test tokens by the response.
        if ($order === null) {
            throw new NotFoundHttpException('No tickets found for that link.');
        }

        $event = $order->event;

        return response()->json([
            'reference' => $order->reference,
            'status' => $order->status,
            'buyer_name' => $order->buyer_name,
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'timezone' => $event->timezone,
                'venue' => $event->venue?->name ?? $event->city,
                'address' => $event->venue?->address_line,
                'city' => $event->city,
                'min_age' => $event->min_age,
                'id_required' => $event->id_required,
                'organizer' => $event->organization->name,
            ],
            'tickets' => $order->tickets
                // Refunded tickets are not shown. A QR that will be turned away
                // at the door is worse than no QR, because the holder does not
                // find out until they are at the front of the queue.
                ->whereNotIn('status', ['refunded', 'void'])
                ->map(fn (Ticket $ticket) => [
                    'id' => $ticket->id,
                    'code' => $ticket->code,
                    'type' => $ticket->ticketType?->name,
                    'holder' => $ticket->holder_name,
                    'status' => $ticket->status,
                    // How many people this one lets in, and how many of them
                    // are already inside — a table that arrives in two groups
                    // needs to see that four of five have been admitted.
                    'admits' => $ticket->admits,
                    'admitted' => $ticket->admitted_count,
                    'qr' => $qr->svg($ticket->code),
                ])
                ->values(),
        ]);
    }
}
