<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\AddOn;
use App\Models\Code;
use App\Models\Event;
use App\Services\Audit\Auditor;
use App\Services\Events\EventReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What an event sells besides tickets.
 *
 * A table's two bottles, a cloakroom pass, a shirt. Priced here in minor units
 * like everything else, and read from here at checkout — no request from a
 * buyer can influence an amount.
 *
 * The rule that decides whether something belongs here rather than on the
 * tickets screen: does anybody walk through a door on it. A Table of 6 is a
 * ticket type because six people do; the bottle on that table is an add-on
 * because nobody does.
 */
class AddOnController extends Controller
{
    /**
     * Enough for a bar list, short of a shop.
     *
     * The list is read, reordered and saved whole, so it cannot be paged —
     * and a checkout offering fifty extras is a checkout nobody finishes.
     */
    public const MAX_PER_EVENT = 20;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        return response()->json(['data' => $this->list($event)]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        $data = $this->validated($request);

        if ($event->addOns()->count() >= self::MAX_PER_EVENT) {
            return response()->json([
                'message' => 'An event can offer up to '.self::MAX_PER_EVENT.' extras. Remove one to add another.',
            ], 422);
        }

        $addOn = $event->addOns()->create($data + [
            'sort_order' => (int) $event->addOns()->max('sort_order') + 1,
        ]);

        $this->auditor->record('add_on.added', $event, $request->user(), metadata: [
            'add_on_id' => $addOn->id,
            'name' => $addOn->name,
            'price' => $addOn->price_amount,
            'currency' => $event->currency,
        ]);

        return response()->json(['data' => $this->present($addOn)], 201);
    }

    public function update(Request $request, Event $event, AddOn $addOn): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        abort_unless($addOn->event_id === $event->id, 404);

        $data = $this->validated($request, updating: true);

        $before = $addOn->only(['name', 'price_amount', 'quantity_available', 'status']);

        // Repricing after some have sold is allowed — a bottle goes up, a
        // shirt goes on offer — and never rewrites what anybody already paid.
        // Order lines snapshot their own name and price precisely so this is
        // safe.
        $addOn->update($data);

        $after = $addOn->fresh()->only(['name', 'price_amount', 'quantity_available', 'status']);
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed !== []) {
            $this->auditor->record(
                in_array('price_amount', $changed, true) ? 'add_on.price_changed' : 'add_on.updated',
                $event,
                $request->user(),
                metadata: [
                    'add_on_id' => $addOn->id,
                    'name' => $addOn->name,
                    'changed' => $changed,
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after' => array_intersect_key($after, array_flip($changed)),
                    'currency' => $event->currency,
                ],
            );
        }

        return response()->json(['data' => $this->present($addOn->fresh())]);
    }

    /**
     * Stop offering it, or take it off the list.
     *
     * One that has sold is closed rather than removed: order lines point at
     * it, and an order that cannot name what was bought cannot be explained.
     * Closing looks like removal to a buyer, which is the part that matters.
     */
    public function destroy(Request $request, Event $event, AddOn $addOn): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        abort_unless($addOn->event_id === $event->id, 404);

        $closing = $this->sold($addOn) > 0;

        // Taken off an event that is on sale: allowed without another review,
        // like any edit while on sale, and kept on the record beside adding
        // one and changing its price.
        if ($event->status === 'published') {
            $this->auditor->record($closing ? 'add_on.closed' : 'add_on.removed', $event, $request->user(), metadata: [
                'add_on_id' => $addOn->id,
                'add_on' => $addOn->name,
            ]);
        }

        if ($closing) {
            $addOn->update(['status' => 'closed']);

            return response()->json([
                'message' => 'No longer offered. The ones already bought are unaffected.',
                'status' => 'closed',
            ]);
        }

        $addOn->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** The order they are offered in, as one list — two writes can disagree. */
    public function reorder(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ]);

        $owned = $event->addOns()->pluck('id')->all();

        if (array_diff($data['ids'], $owned) !== []) {
            return response()->json([
                'message' => 'That list includes an extra that is not on this event.',
            ], 422);
        }

        DB::transaction(function () use ($data, $event) {
            foreach ($data['ids'] as $position => $id) {
                $event->addOns()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['data' => $this->list($event)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Minor units. Zero is allowed: a free cloakroom pass with a
            // limited number of places is a real thing to sell.
            'price_amount' => [$required, 'integer', 'min:0', 'max:100000000'],
            'quantity_available' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_per_order' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['sometimes', Rule::in(['on_sale', 'closed'])],
        ]);
    }

    /** How many have been paid for. */
    private function sold(AddOn $addOn): int
    {
        return (int) DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.add_on_id', $addOn->id)
            ->whereIn('orders.status', Code::PAID_STATUSES)
            ->sum('order_lines.quantity');
    }

    /** @return list<array<string, mixed>> */
    private function list(Event $event): array
    {
        return $event->addOns()->get()->map(fn (AddOn $addOn) => $this->present($addOn))->all();
    }

    /** @return array<string, mixed> */
    private function present(AddOn $addOn): array
    {
        $currency = $addOn->event->currency;

        return [
            'id' => $addOn->id,
            'name' => $addOn->name,
            'description' => $addOn->description,
            'price' => ['amount' => (int) $addOn->price_amount, 'currency' => $currency],
            'quantity_available' => $addOn->quantity_available,
            'max_per_order' => $addOn->max_per_order,
            'status' => $addOn->status,
            'sort_order' => $addOn->sort_order,
            // The two numbers an organizer opens this screen for: how many
            // have gone, and how many are left once baskets in progress are
            // counted.
            'sold' => $this->sold($addOn),
            'remaining' => $addOn->remainingNow(),
        ];
    }
}
