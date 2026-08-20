<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Services\Refunds\RefundRefused;
use App\Services\Refunds\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Orders on an event, and returning money for them.
 *
 * Refunds are requested by ticket. The console never sends an amount and this
 * controller would not accept one — that rule is the same one that stops a
 * buyer choosing what to pay, applied at the other end.
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    /**
     * What has been sold, and what is left to refund on each order.
     */
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('viewSales', $event);

        $orders = $event->orders()
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->with(['tickets', 'lines'])
            ->withSum(['refunds as refunded_amount' => fn ($q) => $q->where('status', 'succeeded')], 'amount')
            ->orderByDesc('paid_at')
            ->paginate(30);

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'buyer_name' => $order->buyer_name,
                'buyer_email' => $order->buyer_email,
                'status' => $order->status,
                'paid_at' => $order->paid_at,
                'currency' => $order->currency,
                'total' => ['amount' => $order->total_amount, 'currency' => $order->currency],
                'refunded' => [
                    'amount' => (int) $order->refunded_amount,
                    'currency' => $order->currency,
                ],
                'refundable' => [
                    'amount' => max(0, $order->total_amount - (int) $order->refunded_amount),
                    'currency' => $order->currency,
                ],
                'tickets' => $order->tickets->map(fn (Ticket $ticket) => [
                    'id' => $ticket->id,
                    'holder_name' => $ticket->holder_name,
                    'ticket_type_name' => $order->lines
                        ->firstWhere('ticket_type_id', $ticket->ticket_type_id)?->ticket_type_name,
                    'status' => $ticket->status,
                    // The code itself is deliberately absent. This list is read
                    // on a laptop in an office, and a ticket code is the thing
                    // that opens a door.
                    'refundable' => ! in_array($ticket->status, ['refunded', 'void'], true),
                ])->values(),
            ])->values(),
            'meta' => ['next' => $orders->nextPageUrl()],
        ]);
    }

    public function store(Request $request, Event $event, Order $order): JsonResponse
    {
        $this->authorize('refund', $event);

        abort_unless($order->event_id === $event->id, 404);

        $data = $request->validate([
            // Absent means the whole order. Present means exactly these, and
            // an empty array is a mistake worth naming rather than silently
            // treating as everything.
            'ticket_ids' => ['sometimes', 'array', 'min:1'],
            'ticket_ids.*' => ['uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $refund = $this->refunds->refund(
                $order,
                $data['ticket_ids'] ?? null,
                $request->user(),
                $data['reason'] ?? null,
            );
        } catch (RefundRefused $e) {
            // The platform declining, in a sentence written to be shown.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $refund->succeeded()) {
            // The processor declining. 502 rather than 422: nothing about the
            // request was wrong, and a retry may well work.
            //
            // The processor's own words are not repeated. "You did not provide
            // an API key" is true, unactionable, and about us — it is on the
            // refund row for support and in the log for whoever is debugging.
            // What an organizer needs is what did and did not happen.
            //
            // `display` marks this as written for a reader, because the console
            // discards 5xx messages by default and should keep doing so.
            return response()->json([
                'message' => 'The payment processor would not complete this refund. '
                    .'No money has moved and the tickets still work — try again shortly.',
                'display' => true,
            ], 502);
        }

        return response()->json($this->present($refund->fresh()->load('tickets')), 201);
    }

    private function present(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'status' => $refund->status,
            'amount' => ['amount' => $refund->amount, 'currency' => $refund->currency],
            'tax' => ['amount' => $refund->tax_amount, 'currency' => $refund->currency],
            'reason' => $refund->reason,
            'ticket_ids' => $refund->tickets->pluck('id')->values(),
            'created_at' => $refund->created_at,
        ];
    }
}
