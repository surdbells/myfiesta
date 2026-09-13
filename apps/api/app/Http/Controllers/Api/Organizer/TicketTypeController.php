<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketTypeResource;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\Audit\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What an event sells.
 *
 * Prices are entered here in minor units, matching every other amount in the
 * system, and this is the only place they can be set. Checkout reads them; no
 * request from a buyer can influence them.
 */
class TicketTypeController extends Controller
{
    /**
     * The most tiers one event may have.
     *
     * A limit where a limit belongs: the tier list is read, reordered and
     * saved as one list, so it cannot be paged — and nothing sold at a door
     * needs fifty ways to buy the same night.
     */
    public const MAX_PER_EVENT = 50;

    public function __construct(private readonly Auditor $auditor) {}

public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        return response()->json([
            'data' => TicketTypeResource::collection(
                $event->ticketTypes()
                    // One extra query for the whole collection rather than one
                    // per type. Only tickets that exist against the door count
                    // — a refunded ticket freed its place back.
                    ->withCount(['tickets as issued_count' => fn ($q) => $q->whereIn(
                        'status', ['valid', 'checked_in']
                    )])
                    ->orderBy('sort_order')
                    ->get()
            ),
        ]);
    }

    /**
     * The order tiers appear in, to a buyer.
     *
     * Worth controlling rather than leaving to whenever each was created: the
     * order on the event page is a selling decision. Early Bird above General
     * reads as a deadline; the other way round reads as a list.
     *
     * One request for the whole list rather than a sort_order per tier, so
     * two tiers can never end up claiming the same place — which is what
     * happens when a client swaps a pair with two writes and the second
     * fails.
     */
    public function reorder(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ]);

        $owned = $event->ticketTypes()->pluck('id')->all();

        // Refused rather than quietly skipped: silently ignoring an id from
        // another event would let this endpoint confirm which ids exist.
        if (array_diff($data['ids'], $owned) !== []) {
            return response()->json([
                'message' => 'That list includes a ticket type that is not on this event.',
            ], 422);
        }

        DB::transaction(function () use ($data, $event) {
            foreach ($data['ids'] as $position => $id) {
                $event->ticketTypes()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        $this->auditor->record(
            'ticket_types.reordered',
            $event,
            $request->user(),
            metadata: ['order' => $data['ids']],
        );

        return response()->json([
            'data' => TicketTypeResource::collection(
                $event->ticketTypes()
                    ->withCount(['tickets as issued_count' => fn ($q) => $q->whereIn(
                        'status', ['valid', 'checked_in']
                    )])
                    ->orderBy('sort_order')
                    ->get()
            ),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        $data = $this->validated($request);

        if ($event->ticketTypes()->count() >= self::MAX_PER_EVENT) {
            return response()->json([
                'message' => 'An event can have up to '.self::MAX_PER_EVENT.' ticket types. Remove one you no longer sell to add another.',
            ], 422);
        }

        $type = $event->ticketTypes()->create($data + ['status' => 'on_sale']);

        return response()->json(new TicketTypeResource($type->load('event')), 201);
    }

    public function update(Request $request, Event $event, TicketType $ticketType): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        abort_unless($ticketType->event_id === $event->id, 404);

        $data = $this->validated($request, updating: true);

        if ($refusal = $this->ladderRefusal($ticketType, $data['opens_after_id'] ?? null)) {
            return response()->json(['message' => $refusal], 422);
        }

        $before = $ticketType->only(['name', 'price_amount', 'quantity_available', 'status']);

        // Repricing after tickets have sold is allowed — early-bird tiers close
        // and prices rise — but it never rewrites what anyone already paid.
        // Orders snapshot their own figures precisely so this is safe.
        $ticketType->update($data);

        /*
         * Only when something an organizer would be asked about actually moved.
         *
         * Recording every PATCH would bury the price change in a hundred
         * no-op saves from somebody tabbing through a form, and the entry that
         * matters is the one somebody goes looking for: who dropped this to
         * zero, and when.
         */
        $after = $ticketType->fresh()->only(['name', 'price_amount', 'quantity_available', 'status']);
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed !== []) {
            $this->auditor->record(
                in_array('price_amount', $changed, true)
                    ? 'ticket.price_changed'
                    : 'ticket.updated',
                $event,
                $request->user(),
                metadata: [
                    'ticket_type_id' => $ticketType->id,
                    'ticket_type' => $ticketType->name,
                    'changed' => $changed,
                    // Both sides, in minor units with the event's currency, so
                    // the entry is readable without fetching anything else.
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after' => array_intersect_key($after, array_flip($changed)),
                    'currency' => $event->currency,
                ],
            );
        }

        return response()->json(new TicketTypeResource($ticketType->fresh()->load('event')));
    }

    /**
     * Remove a ticket type, or close it.
     *
     * A type with tickets against it is closed rather than deleted: those
     * tickets and their order lines point at it, and removing it would make an
     * order impossible to explain. Soft-deleting looks like removal to the
     * organizer without breaking anything behind it.
     */
    public function destroy(Request $request, Event $event, TicketType $ticketType): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        abort_unless($ticketType->event_id === $event->id, 404);

        $sold = $ticketType->tickets()->exists();

        if ($sold) {
            $ticketType->update(['status' => 'closed']);

            return response()->json([
                'message' => 'Sales closed. Tickets already issued keep working.',
                'status' => 'closed',
            ]);
        }

        $ticketType->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /**
     * A ladder has to end somewhere.
     *
     * Tier 2 opening after Tier 1 opening after Tier 2 would leave both
     * waiting on the other for ever, and nothing on sale.
     */
    private function ladderRefusal(TicketType $type, ?string $opensAfterId): ?string
    {
        if ($opensAfterId === null) {
            return null;
        }

        if ($opensAfterId === $type->id) {
            return 'A ticket cannot wait for itself to sell out.';
        }

        $seen = [$type->id];
        $next = TicketType::find($opensAfterId);

        while ($next !== null && $next->opens_after_id !== null) {
            if (in_array($next->opens_after_id, $seen, true)) {
                return 'That would make these tickets wait for each other, so none would ever go on sale.';
            }

            $seen[] = $next->id;
            $next = TicketType::find($next->opens_after_id);
        }

        return null;
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            // Minor units. 2500 is $25.00 — the client converts for display,
            // never for storage.
            'price_amount' => [$required, 'integer', 'min:0', 'max:100000000'],
            'admits' => ['nullable', 'integer', 'min:1', 'max:50'],
            'quantity_available' => ['nullable', 'integer', 'min:0'],
            'max_per_order' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date', 'after:sales_start_at'],
            'status' => ['sometimes', 'in:on_sale,hidden,closed'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'opens_after_id' => ['nullable', 'uuid', Rule::exists('ticket_types', 'id')->where('event_id', $request->route('event')->id)->whereNull('deleted_at')],
        ]);
    }
}
