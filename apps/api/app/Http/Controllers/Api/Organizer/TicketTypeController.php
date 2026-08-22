<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketTypeResource;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\Audit\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What an event sells.
 *
 * Prices are entered here in minor units, matching every other amount in the
 * system, and this is the only place they can be set. Checkout reads them; no
 * request from a buyer can influence them.
 */
class TicketTypeController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        return response()->json([
            'data' => TicketTypeResource::collection(
                $event->ticketTypes()->orderBy('sort_order')->get()
            ),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        $data = $this->validated($request);

        $type = $event->ticketTypes()->create($data + ['status' => 'on_sale']);

        return response()->json(new TicketTypeResource($type->load('event')), 201);
    }

    public function update(Request $request, Event $event, TicketType $ticketType): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        abort_unless($ticketType->event_id === $event->id, 404);

        $data = $this->validated($request, updating: true);

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
        ]);
    }
}
