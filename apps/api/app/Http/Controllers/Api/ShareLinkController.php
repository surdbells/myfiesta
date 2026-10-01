<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\Sharing\ShareLinks;
use App\Services\Sharing\ShareOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A friend's link, from either end.
 *
 * store: "Get my link", on the phone's ticket screen. A buyer is given their
 * link when they pay. Somebody else holding a ticket to the night — passed on
 * to them, or bought before the organizer started the offer under an address
 * the phone does not know — asks here. Signed in, and holding a ticket that
 * will still get them in: a link is a discount a friend can spend, and is
 * handed to people coming, not to anybody who asks. Asking twice gets the
 * same link.
 *
 * show: whether a ref really is a friend's link that takes money off this
 * night, for the event page to greet somebody who arrived by one. The page
 * is the same for everybody and cannot say; the ref's shape alone cannot
 * either, since a promoter can pick a slug that looks like one, and a link
 * stops working when its holder is refunded (ShareLinks::live).
 */
class ShareLinkController extends Controller
{
    public function store(Request $request, string $slug, ShareLinks $links): JsonResponse
    {
        $event = Event::query()->where('slug', $slug)->with('organization:id,name')->first();

        abort_if($event === null, 404, 'Event not found.');

        $user = $request->user();

        $holds = Ticket::query()
            ->where('event_id', $event->id)
            ->where('owner_user_id', $user->id)
            ->whereIn('status', ShareLinks::COMING)
            ->exists();

        if (! $holds) {
            return response()->json(['message' => 'Your link comes with a ticket to this night, and this account does not hold one.'], 403);
        }

        if ($links->bpsFor($event) === null) {
            return response()->json(['message' => ShareOffers::offered($event)
                ? 'Sales for this night are over, so there is no discount left to share.'
                : 'This night has no friend discount to share.'], 422);
        }

        $link = $links->forHolder($event, (string) $user->email, $user->id);

        return response()->json([
            'data' => $links->present($link, $event, $event->organization?->name),
        ]);
    }

    public function show(Request $request, string $slug, ShareLinks $links): JsonResponse
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('kind', 'ticketed')
            ->first();

        $ref = $request->query('ref');
        $link = $event === null || ! is_string($ref) ? null : $links->live($event, $ref);

        // One answer for every kind of no — no such night, no offer, not a
        // friend's link, a link that has stopped — so asking says nothing
        // about whose links exist.
        if ($link === null) {
            return response()->json(['message' => 'That link takes nothing off this night.'], 404);
        }

        return response()->json([
            'data' => ['discount_bps' => (int) $links->bpsFor($event)],
        ]);
    }
}
