<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketTypeResource;
use App\Models\Event;
use App\Services\Checkout\Pricer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A presale code, typed on the ticket page, showing what it opens.
 *
 * The tiers come back so the page can show them before anything is chosen:
 * a hidden tier is otherwise invisible, and one not yet on sale is shown as
 * "on sale Friday" to everybody else. Only tiers that can still be bought are
 * returned; a code does not reopen sales that have ended or a sold-out tier.
 *
 * Throttled hard (routes/api.php). The answer confirms a code exists, and a
 * presale code guessed is a presale anybody can join.
 */
class AccessCodeController extends Controller
{
    public function __invoke(Request $request, string $slug, Pricer $pricer): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('kind', 'ticketed')
            ->first();

        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        try {
            $code = $pricer->resolveAccess($event, $data['code']);
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tiers = $code->unlocks()
            ->where('event_id', $event->id)
            ->whereIn('status', ['on_sale', 'hidden', 'sold_out'])
            ->where(fn ($q) => $q->whereNull('sales_end_at')->orWhere('sales_end_at', '>', now()))
            ->with('event')
            ->orderBy('sort_order')
            ->get();

        if ($tiers->isEmpty()) {
            // Valid, but nothing on this event left to open. Said as such rather
            // than as "invalid", which would send somebody back to retype it.
            return response()->json(['message' => 'That code has nothing left to unlock for this event.'], 422);
        }

        return response()->json([
            'code' => $code->code,
            'ticket_types' => TicketTypeResource::collection($tiers),
        ]);
    }
}
