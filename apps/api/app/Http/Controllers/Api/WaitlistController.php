<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Waitlist\Waitlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Joining the waitlist from a sold-out ticket page.
 *
 * The answer is the same whether the address was new or already on the list,
 * so the endpoint cannot be used to find out who is waiting for what.
 */
class WaitlistController extends Controller
{
    public function __invoke(Request $request, string $slug, Waitlist $waitlist): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('kind', 'ticketed')
            ->where('starts_at', '>', now())
            ->first();

        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        if ($waitlist->hasTicketsOnSale($event)) {
            return response()->json(['message' => 'Tickets are on sale right now — no need to wait.'], 422);
        }

        $waitlist->join($event, $data['email'], $data['name'] ?? null, (int) ($data['quantity'] ?? 1));

        return response()->json([
            'message' => "You're on the waitlist. We'll email you if tickets come up.",
        ], 201);
    }
}
