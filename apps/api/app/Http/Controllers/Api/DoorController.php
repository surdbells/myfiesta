<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\OrderAnswer;
use App\Models\Ticket;
use App\Services\Checkout\CheckoutService;
use App\Services\Door\CheckInService;
use App\Services\Door\DoorList;
use App\Services\Door\DoorPasses;
use App\Services\Door\DoorSales;
use App\Services\Door\ScanOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Scanning at the door.
 *
 * Reached only with a door- or organizer-scoped token, and a door token is
 * bound to one event — the middleware refuses it for any other. That is the
 * boundary the mobile app cannot enforce for itself, since it carries the
 * organizer interface in the same binary.
 */
class DoorController extends Controller
{
    public function __construct(
        private readonly CheckInService $door,
        private readonly DoorList $list,
        private readonly DoorPasses $passes,
        private readonly DoorSales $sales,
    ) {}

    public function scan(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            // How many of the party are going in now. Absent admits everyone
            // still outstanding, which is the right default for an ordinary
            // single-admission ticket.
            'party' => ['nullable', 'integer', 'min:1', 'max:50'],
            // The scan's own id. A phone that times out waiting for an answer
            // falls back to its offline list and sends the scan again later;
            // this is what stops the retry refusing a guest already let in.
            'client_id' => ['nullable', 'uuid'],
        ]);

        $outcome = $this->door->scan(
            $validated['code'],
            $event->id,
            $request->user(),
            $validated['party'] ?? null,
            $validated['client_id'] ?? null,
            $this->passes->forToken($request->user()->currentAccessToken())?->id,
        );

        return response()->json($this->present($outcome, $event));
    }

    /**
     * The event's tickets, for a phone to keep deciding with when signal goes.
     *
     * Hashes, not codes — see DoorList. Fetched when the door screen opens and
     * refreshed while it has a connection, so the list is as fresh as the
     * last moment there was signal.
     */
    public function list(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        return response()->json($this->list->for($event));
    }

    /**
     * Scans a door made with no connection, sent once it has one again.
     *
     * A batch, because a door that lost signal for an hour has an hour of
     * scans, and one request per scan on a connection that has only just come
     * back is how the backlog never clears. A batch that does not validate is
     * refused whole, before anything is recorded, so the phone keeps its queue
     * intact; each scan in a valid batch is then decided in its own
     * transaction, one ticket lock at a time.
     *
     * Safe to send twice. Every scan carries the id the phone gave it, and one
     * the server has already recorded comes back with its original result —
     * which is what lets a phone retry a sync whose response it never got.
     */
    public function sync(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        $validated = $request->validate([
            'scans' => ['required', 'array', 'min:1', 'max:500'],
            'scans.*.client_id' => ['required', 'uuid', 'distinct'],
            'scans.*.code' => ['required', 'string', 'max:32'],
            'scans.*.party' => ['nullable', 'integer', 'min:1', 'max:50'],
            'scans.*.offline_result' => ['required', 'in:accepted,duplicate,not_found,void,over_capacity'],
            'scans.*.scanned_at' => ['required', 'date'],
        ]);

        $results = [];
        $passId = $this->passes->forToken($request->user()->currentAccessToken())?->id;

        foreach ($validated['scans'] as $scan) {
            $outcome = $this->door->recordOffline(
                $scan['code'],
                $event->id,
                $request->user(),
                $scan['party'] ?? null,
                $scan['client_id'],
                $scan['offline_result'],
                Carbon::parse($scan['scanned_at']),
                $passId,
            );

            $results[] = ['client_id' => $scan['client_id']] + $this->present($outcome, $event);
        }

        $conflicts = array_values(array_filter($results, fn ($r) => $r['conflict'] !== null));

        return response()->json([
            'data' => $results,
            // Said separately, because it is the only part anybody on the door
            // needs to read: who went in that should not have, and who was
            // turned away that should not have been.
            'conflicts' => $conflicts,
        ]);
    }

    /**
     * What the door can sell, and what it costs.
     *
     * The same tiers a buyer sees, with what is genuinely left on each —
     * somebody selling the last four tickets needs the number to be true at
     * the moment they say it out loud.
     */
    public function sellable(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        return response()->json([
            'currency' => $event->currency,
            'methods' => DoorSales::METHODS,
            'ticket_types' => $event->ticketTypes()
                ->whereIn('status', ['on_sale', 'sold_out'])
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'price' => ['amount' => (int) $type->price_amount, 'currency' => $event->currency],
                    'admits' => $type->admits,
                    'remaining' => $type->remainingNow(),
                    'sold_out' => $type->remainingNow() === 0,
                ])
                ->values(),
        ]);
    }

    /**
     * What to say out loud before any money changes hands.
     *
     * Priced by the server, like every other figure in this system. The phone
     * could add up the tiers itself and be a cent out on the tax once the
     * rounding landed differently — which is a cent somebody is holding in
     * their hand while a screen disagrees with them.
     */
    public function quote(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.ticket_type_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $quantities = [];

        foreach ($validated['items'] as $item) {
            $id = $item['ticket_type_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
        }

        try {
            $quote = app(CheckoutService::class)->quote(
                event: $event,
                quantities: $quantities,
                channel: 'door',
            );
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'total' => ['amount' => $quote->total->amount, 'currency' => $quote->currency()],
            // Said separately because a door often has to answer "how much of
            // that is tax" out loud, and because there is deliberately no
            // service charge on money the platform never touched.
            'tax' => ['amount' => $quote->tax->amount, 'currency' => $quote->currency()],
            'tax_label' => $quote->taxRate?->name,
        ]);
    }

    /**
     * Sell to somebody standing in front of you.
     *
     * Reachable by a door token, deliberately: the person selling walk-ups is
     * the person on the door, and a sale that needs an owner to be standing
     * there is a sale that does not happen. What makes that safe is that the
     * order names who took it and on which phone, and the audit log has the
     * same entry — a till with a name on it is the control that matters when
     * money is handled in a doorway.
     */
    public function sell(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.ticket_type_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'method' => ['required', Rule::in(DoorSales::METHODS)],
            // Both optional. A walk-up paying cash gives neither, and asking
            // for an address with a queue behind them is how a door stops.
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ]);

        $quantities = [];

        foreach ($validated['items'] as $item) {
            $id = $item['ticket_type_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
        }

        try {
            $order = $this->sales->sell(
                event: $event,
                quantities: $quantities,
                method: $validated['method'],
                soldBy: $request->user(),
                doorPassId: $this->passes->forToken($request->user()->currentAccessToken())?->id,
                buyerName: $validated['name'] ?? null,
                buyerEmail: $validated['email'] ?? null,
            );
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'reference' => $order->reference,
            'total' => ['amount' => $order->total_amount, 'currency' => $order->currency],
            'method' => $order->payment_method,
            // The codes, because the phone that sold them is usually the
            // phone that scans them straight back in.
            'tickets' => $order->tickets()->get()->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'type' => $ticket->ticketType?->name,
                'admits' => $ticket->admits,
            ])->values(),
            'emailed' => filled($order->buyer_email),
        ], 201);
    }

    /** The till, for whoever is counting it. */
    public function takings(Request $request, Event $event): JsonResponse
    {
        $this->authorizeDoor($request, $event);

        return response()->json($this->sales->takings($event));
    }

    /**
     * Only the organizer path needs authorising.
     *
     * A `door:{event_id}` token already names this event. It was minted
     * deliberately, for one night, and it is the whole grant — that is what
     * lets a venue hand a phone to somebody working the door without first
     * creating them an account and a membership.
     *
     * An organizer token names nothing. It says somebody organizes something,
     * so without this check every organizer on the platform could scan — and
     * now download the ticket list of — every other organizer's event.
     */
    private function authorizeDoor(Request $request, Event $event): void
    {
        $token = $request->user()->currentAccessToken();

        if (! $token->can(TokenAbility::doorFor($event->id))) {
            $this->authorize('scan', $event);

            return;
        }

        // A door pass is only as good as the member who made it. Somebody
        // moved from manager to marketing can no longer work the door, and
        // neither can the phones they handed out.
        if ($this->passes->forToken($token) !== null) {
            $this->authorize('scan', $event);
        }
    }

    /**
     * The answers written on one ticket, ready to read at arm's length.
     *
     * @return list<array{label: ?string, value: string}>
     */
    private function answers(Ticket $ticket): array
    {
        return $ticket->answers()
            ->with('question')
            ->get()
            ->map(fn (OrderAnswer $answer) => [
                'label' => $answer->question?->label,
                'value' => $answer->asText(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(ScanOutcome $outcome, Event $event): array
    {
        return [
            'result' => $outcome->result,
            'accepted' => $outcome->admittedAnyone(),
            // How many this scan let in, and how many of the party are still
            // outside. The second is what tells the door whether to keep the
            // ticket open for the rest of a table.
            'admitted' => $outcome->admitted,
            'remaining' => $outcome->remaining,
            'message' => $outcome->message,
            'offline_result' => $outcome->offlineResult,
            'conflict' => $outcome->conflict(),
            'ticket' => $outcome->ticket && $outcome->ticket->event_id === $event->id
                ? [
                    'holder_name' => $outcome->ticket->holder_name,
                    'type' => $outcome->ticket->ticketType?->name,
                    'admits' => $outcome->ticket->admits,
                    'admitted_count' => $outcome->ticket->admitted_count,
                    // What this person was asked at checkout, which is the
                    // whole reason for collecting it: a name to check against
                    // an ID, a table number, an access requirement.
                    //
                    // Only what was asked of them. What the buyer answered for
                    // the order — how they heard about the night — is not
                    // somebody on a door's business.
                    'answers' => $this->answers($outcome->ticket),
                ]
                // Nothing about a ticket belonging to another event. A door
                // token is scoped to one event, and leaking a guest's name from
                // a different one would be a data leak dressed as helpfulness.
                : null,
        ];
    }
}
