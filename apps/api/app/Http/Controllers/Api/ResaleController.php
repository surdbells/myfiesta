<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Services\Resale\Resale;
use App\Services\Resale\ResaleRefused;
use App\Services\Tickets\TicketAccessPayload;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Giving a ticket back, from the link in the buyer's email.
 *
 * Reached by the order's access token rather than an account, like everything
 * else a ticket holder does here: guest checkout is the primary path, and a
 * return that required signing in would be closed to most of the people
 * holding tickets.
 *
 * The token names the order; the ticket must be on it. That is the whole
 * authorisation, and it is the same credential that already shows the QR
 * codes — somebody holding it can already get into the event, so they can
 * certainly give the ticket back.
 */
class ResaleController extends Controller
{
    public function __construct(
        private readonly Resale $resale,
        private readonly TicketAccessPayload $payload,
    ) {}

    public function store(string $token, string $ticketId): JsonResponse
    {
        $ticket = $this->ticket($token, $ticketId);

        try {
            $listing = $this->resale->list($ticket);
        } catch (ResaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => 'Given back. You will get what you paid as soon as somebody takes the place — '
                .'we will email you when that happens.',
            'listing' => $this->present($listing),
            // The page as it now is. The client shows this rather than asking
            // again: the same GET can come back from a cache holding the page
            // as it was, QR and all, for a ticket that no longer works.
            'access' => $this->payload->for($ticket->order),
        ], 201);
    }

    public function destroy(string $token, string $ticketId): JsonResponse
    {
        $ticket = $this->ticket($token, $ticketId);

        $listing = ResaleListing::query()
            ->where('ticket_id', $ticket->id)
            ->where('status', 'listed')
            ->first();

        if ($listing === null) {
            return response()->json(['message' => 'That ticket is not waiting to be taken.'], 422);
        }

        try {
            $this->resale->cancel($listing);
        } catch (ResaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'message' => 'Kept. The ticket works again.',
            'access' => $this->payload->for($ticket->fresh()->order),
        ]);
    }

    private function ticket(string $token, string $ticketId): Ticket
    {
        $order = Order::where('access_token', $token)->first();

        // 404 for a wrong token and for a ticket that is not on it: a
        // different answer for the two would let somebody learn which ticket
        // ids exist.
        if ($order === null) {
            throw new NotFoundHttpException('No tickets found for that link.');
        }

        $ticket = Ticket::query()
            ->with('event')
            ->whereKey($ticketId)
            ->where('order_id', $order->id)
            ->first();

        if ($ticket === null) {
            throw new NotFoundHttpException('That ticket is not on this order.');
        }

        return $ticket;
    }

    /** @return array<string, mixed> */
    private function present(ResaleListing $listing): array
    {
        return [
            'id' => $listing->id,
            'status' => $listing->status,
            'price' => ['amount' => (int) $listing->price_amount, 'currency' => $listing->currency],
            'listed_at' => $listing->listed_at?->toIso8601String(),
        ];
    }
}
