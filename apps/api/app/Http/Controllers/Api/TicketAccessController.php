<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Tickets\TicketAccessPayload;
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
    public function __construct(private readonly TicketAccessPayload $payload) {}

    public function __invoke(string $token): JsonResponse
    {
        $order = Order::where('access_token', $token)->first();

        // 404 rather than 403. A different answer for a real token with a typo
        // and an invented one would let somebody test tokens by the response.
        if ($order === null) {
            throw new NotFoundHttpException('No tickets found for that link.');
        }

        return response()->json($this->payload->for($order));
    }
}
