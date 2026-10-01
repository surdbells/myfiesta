<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketTransfer;
use App\Services\Tickets\TicketAccessPayload;
use App\Services\Tickets\TicketHandover;
use App\Services\Tickets\TicketHandoverRefused;
use App\Services\Tickets\TicketLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Send to someone", from a ticket link.
 *
 * Reached by the link's token rather than an account, like giving a ticket
 * back (ResaleController): guest checkout is the primary path, and a transfer
 * that required signing in would be closed to most people holding tickets.
 * The token names the order, and the ticket must be on it and still the
 * buyer's; or it is the link a ticket was sent with, and names that ticket.
 * That is the whole authorisation, and it is the same credential that
 * already shows the QR — somebody holding it can already walk in on it.
 *
 * The handover itself is TicketHandover's, the same one the phone uses.
 */
class TicketTransferController extends Controller
{
    public function __construct(
        private readonly TicketHandover $handover,
        private readonly TicketAccessPayload $payload,
    ) {}

    public function store(Request $request, string $token, string $ticketId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $link = TicketLink::find($token);
        $ticket = $link?->ticket($ticketId);

        // 404 for a wrong token, a closed one, and a ticket that is not the
        // link's: a different answer for each would let somebody learn which
        // tokens and ticket ids exist.
        if ($link === null || $ticket === null) {
            throw new NotFoundHttpException('That ticket is not on this link.');
        }

        try {
            $transfer = $this->handover->send($ticket, $validated['email'], $validated['name'], null);
        } catch (TicketHandoverRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        // The page as it now is, without the ticket. The client shows this
        // rather than asking again, as it does after giving one back: the
        // same GET can come back from a cache holding the page as it was, QR
        // and all.
        $access = $link->order !== null
            ? $this->payload->for($link->order->refresh())
            : $this->payload->forTransfer(TicketTransfer::query()->findOrFail($link->transfer?->id));

        return response()->json([
            'message' => "Sent to {$transfer->to_email}. They have an email with the ticket and a link to it, "
                .'and the code that was on this page no longer gets anybody in.',
            'access' => $access,
        ]);
    }
}
