<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * An organizer's own events.
 *
 * Every action is authorised against the organization that owns the record,
 * not against the token alone. The previous platform's equivalent checked a
 * token through a verifier that always returned success, so any account could
 * edit any event by id.
 */
class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationIds = $request->user()->organizations->pluck('id');

        $events = Event::query()
            ->whereIn('organization_id', $organizationIds)
            ->withCount([
                'tickets as tickets_issued' => fn ($q) => $q->whereIn('status', ['valid', 'checked_in']),
                'tickets as checked_in' => fn ($q) => $q->where('status', 'checked_in'),
            ])
            // Upcoming first, soonest at the top — that is what needs
            // attention. Past events fall below rather than disappearing.
            ->orderByRaw('starts_at < now()')
            ->orderBy('starts_at')
            ->paginate(30);

        return response()->json([
            'data' => $events->getCollection()->map(fn (Event $e) => [
                'id' => $e->id,
                'slug' => $e->slug,
                'title' => $e->title,
                'kind' => $e->kind,
                'status' => $e->status,
                'starts_at' => $e->starts_at,
                'timezone' => $e->timezone,
                'city' => $e->city,
                'currency' => $e->currency,
                'tickets_issued' => $e->tickets_issued,
                'checked_in' => $e->checked_in,
            ])->values(),
            'meta' => ['next' => $events->nextPageUrl()],
        ]);
    }

    /**
     * One event, in full.
     *
     * Separate from the list on purpose. A list carries what a list needs —
     * title, date, counts — and an edit form needs every field it is allowed to
     * change. Widening the list to serve the form would send description and
     * address for thirty events to render a table that shows neither.
     */
    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json([
            'id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title,
            'kind' => $event->kind,
            'status' => $event->status,
            'description' => $event->description,
            'currency' => $event->currency,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'timezone' => $event->timezone,
            'city' => $event->city,
            'subdivision' => $event->subdivision,
            'country' => $event->country,
            'category' => $event->category,
            'min_age' => $event->min_age,
            'id_required' => $event->id_required,
            'poster_url' => $event->poster_path
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($event->poster_path)
                : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['nullable', 'in:ticketed,invitation'],
            'description' => ['nullable', 'string', 'max:8000'],
            'currency' => ['required', 'in:CAD,NGN'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'timezone' => ['required', 'timezone'],
            'city' => ['required', 'string', 'max:120'],
            'subdivision' => ['nullable', 'string', 'max:8'],
            'country' => ['required', 'string', 'size:2'],
            'category' => ['nullable', 'string', 'max:60'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        $organization = Organization::findOrFail($data['organization_id']);

        $this->authorize('create', [Event::class, $organization->id]);

        $event = Event::create($data + [
            'slug' => $this->slugFor($data['title']),
            'country' => strtoupper($data['country']),
            'status' => 'draft',
        ]);

        return response()->json(
            new EventResource($event->load(['organization', 'ticketTypes'])),
            201,
        );
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:8000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date'],
            'timezone' => ['sometimes', 'timezone'],
            'city' => ['sometimes', 'string', 'max:120'],
            'subdivision' => ['nullable', 'string', 'max:8'],
            'country' => ['sometimes', 'string', 'size:2'],
            'category' => ['nullable', 'string', 'max:60'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:99'],
            'id_required' => ['sometimes', 'boolean'],
        ]);

        // Currency is absent on purpose. Changing it after a sale would leave
        // orders denominated in one currency under an event claiming another,
        // and the ledger has no way to express that.
        $event->update($data);

        return response()->json(
            new EventResource($event->fresh()->load(['organization', 'ticketTypes'])),
        );
    }

    /**
     * Publish, or take back down.
     *
     * Publishing is refused without something to sell: a published event with
     * no tickets is a shared link that disappoints everyone who follows it.
     */
    public function publish(Request $request, Event $event): JsonResponse
    {
        $this->authorize('publish', $event);

        $data = $request->validate([
            'status' => ['required', 'in:draft,published'],
        ]);

        if ($data['status'] === 'draft') {
            $event->update(['status' => 'draft']);

            return response()->json(['status' => 'draft']);
        }

        $needsTickets = $event->kind === 'ticketed'
            && ! $event->ticketTypes()->where('status', 'on_sale')->exists();

        if ($needsTickets) {
            return response()->json([
                'message' => 'Add at least one ticket on sale before publishing.',
            ], 422);
        }

        $event->update([
            'status' => 'published',
            // Kept from the first publish, so unpublishing and republishing
            // does not make an old event look newly announced.
            'published_at' => $event->published_at ?? now(),
        ]);

        return response()->json(['status' => 'published']);
    }

    /**
     * The numbers behind one event.
     *
     * Read from the ledger rather than recomputed, so what an organizer sees
     * here and what a settlement eventually pays out cannot disagree.
     */
    public function summary(Request $request, Event $event): JsonResponse
    {
        $this->authorize('viewSales', $event);

        $entries = LedgerEntry::where('event_id', $event->id)->get();
        $currency = $event->currency;

        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $currency];
        $sum = fn (string $type) => (int) $entries->where('type', $type)->sum('amount');

        return response()->json([
            'currency' => $currency,
            'gross' => $money($sum('sale')),
            'discounts' => $money(abs($sum('discount'))),
            'tax' => $money(abs($sum('tax'))),
            'commission' => $money(abs($sum('commission'))),
            'refunds' => $money(abs($sum('refund'))),
            // What the organizer is actually owed, and the only figure here
            // that should ever be described as theirs.
            'net' => $money((int) $entries->sum('amount')),
            'orders' => $event->orders()->where('status', 'paid')->count(),
            'tickets_issued' => $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count(),
            'checked_in' => $event->tickets()->where('status', 'checked_in')->count(),
        ]);
    }

    /**
     * Slugs are permanent once shared, so a collision takes a suffix rather
     * than silently claiming an existing event's URL.
     */
    private function slugFor(string $title): string
    {
        $base = Str::slug($title) ?: 'event';
        $slug = $base;
        $n = 2;

        while (Event::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
