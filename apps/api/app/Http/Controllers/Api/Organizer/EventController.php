<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Services\Audit\Auditor;
use App\Services\Events\EventCanceller;
use App\Services\Events\EventDuplicator;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * A page of events.
     *
     * `when=upcoming` is soonest first and `when=past` most recent first, each
     * paged on its own, which is how the console shows them. Without it the
     * two run together, upcoming first — kept for any caller that pages
     * through everything.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['when' => ['sometimes', 'in:upcoming,past']]);

        $query = Event::query()
            ->whereIn('organization_id', $this->organizationIds($request))
            ->withCount([
                'tickets as tickets_issued' => fn ($q) => $q->whereIn('status', ['valid', 'checked_in']),
                'tickets as checked_in' => fn ($q) => $q->where('status', 'checked_in'),
            ]);

        match ($request->query('when')) {
            'upcoming' => $query->where('starts_at', '>=', now())->orderBy('starts_at'),
            // Most recent first. The old combined order ran past events
            // oldest-first, so with enough history last week's night was the
            // one that fell off the end of the page.
            'past' => $query->where('starts_at', '<', now())->orderByDesc('starts_at'),
            default => $query->orderByRaw('starts_at < now()')->orderBy('starts_at'),
        };

        $events = $query->orderBy('id')->paginate(Paging::perPage($request, 30));

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
            'meta' => Paging::meta($events),
        ]);
    }

    /**
     * Every event's id and title, for a filter.
     *
     * Not paged: a select that holds the first hundred events is a filter that
     * cannot find the hundred-and-first. Three narrow columns stay small even
     * for an organization with years of weekly nights.
     */
    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Event::query()
                ->whereIn('organization_id', $this->organizationIds($request))
                ->orderByDesc('starts_at')
                ->get(['id', 'title', 'starts_at'])
                ->map(fn (Event $e) => ['id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at])
                ->values(),
        ]);
    }

    /**
     * The organizations a list is about.
     *
     * The one the console has selected, when it says — checked against
     * membership, never trusted. Without the header, every organization this
     * person belongs to, as before; somebody in two used to see both mixed
     * under one name in the console, because it never said which it meant.
     *
     * @return list<string>
     */
    private function organizationIds(Request $request): array
    {
        $memberships = $request->user()->organizations->pluck('id')->all();

        $asked = $request->header('X-Organization');

        if ($asked === null || $asked === '') {
            return $memberships;
        }

        abort_unless(in_array($asked, $memberships, true), 403, 'You are not a member of that organization.');

        return [$asked];
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
            'poster_url' => $event->banner?->renditionUrl('display'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['nullable', 'in:ticketed,invitation'],
            'description' => ['nullable', 'string', 'max:20000'],
            'currency' => ['required', 'in:CAD,NGN'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'timezone' => ['required', 'timezone'],
            'city' => ['required', 'string', 'max:120'],
            'subdivision' => ['nullable', 'string', 'max:8'],
            'country' => ['required', 'string', 'size:2'],
            'category' => ['nullable', Rule::in(config('events.categories'))],
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

    /**
     * Copy an event into a new draft.
     *
     * Authorised as a create against the same organization, not as an update
     * of the original: this makes a new event, and somebody who may read an
     * event must not be able to mint one from it.
     */
    public function duplicate(Request $request, Event $event, EventDuplicator $duplicator): JsonResponse
    {
        $this->authorize('view', $event);
        $this->authorize('create', [Event::class, $event->organization_id]);

        $data = $request->validate([
            'starts_at' => ['nullable', 'date', 'after:now'],
            'title' => ['nullable', 'string', 'max:160'],
        ], [
            'starts_at.after' => 'Pick a date in the future for the copy.',
        ]);

        $copy = $duplicator->duplicate(
            $event,
            isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null,
            $data['title'] ?? null,
            $request->user(),
        );

        return response()->json(
            new EventResource($copy->load(['organization', 'ticketTypes'])),
            201,
        );
    }

    /**
     * What cancelling this event would involve.
     *
     * Asked for before the confirmation is shown, so the organizer sees how
     * many people they are about to tell and how much money is about to move
     * before they decide — rather than finding out from the result.
     */
    public function cancellationPreview(Request $request, Event $event, EventCanceller $canceller): JsonResponse
    {
        $this->authorize('cancel', $event);

        return response()->json($canceller->preview($event));
    }

    /**
     * Call it off.
     *
     * Separate from publish because they are different decisions. Unpublishing
     * hides a link and leaves every ticket working; cancelling tells everybody
     * holding one that the night is not happening and, by default, gives them
     * their money back.
     */
    public function cancel(Request $request, Event $event, EventCanceller $canceller): JsonResponse
    {
        $this->authorize('cancel', $event);

        $data = $request->validate([
            // Required, and it reaches ticket holders verbatim. An organizer
            // who has to write the sentence is more likely to mean it than one
            // clicking through a confirm dialog.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'refund' => ['sometimes', 'boolean'],
        ], [
            'reason.required' => 'Say why — this is sent to everyone holding a ticket.',
            'reason.min' => 'Give people a real explanation, not a word.',
        ]);

        try {
            $outcome = $canceller->cancel(
                $event,
                $request->user(),
                trim($data['reason']),
                $data['refund'] ?? true,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $this->cancellationSummary($outcome),
            'status' => EventStatus::Cancelled->value,
        ] + $outcome);
    }

    /** Says what actually happened, including the part that did not work. */
    private function cancellationSummary(array $outcome): string
    {
        $parts = ["{$outcome['notified']} people told"];

        if ($outcome['refunded'] > 0) {
            $parts[] = "{$outcome['refunded']} orders refunded";
        }

        if ($outcome['failed'] > 0) {
            // Surfaced rather than buried in a log. These are the orders that
            // need a person, and an organizer who is not told will find out
            // from the buyer.
            $parts[] = "{$outcome['failed']} refunds could not be sent — refund those by hand";
        }

        return 'Event cancelled. '.implode(', ', $parts).'.';
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        // A cancelled event is a historical record. People bought tickets to
        // what it said and some were refunded on that basis; editing it
        // afterwards rewrites what they were told.
        if (! EventStatus::from($event->status)->isEditable()) {
            return response()->json([
                'message' => 'A cancelled event cannot be edited. Copy it to a new date instead.',
            ], 422);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:20000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date'],
            'timezone' => ['sometimes', 'timezone'],
            'city' => ['sometimes', 'string', 'max:120'],
            'subdivision' => ['nullable', 'string', 'max:8'],
            'country' => ['sometimes', 'string', 'size:2'],
            // An event already filed under something off the list keeps it until
            // it is changed; it cannot be changed to something else off the list.
            'category' => ['nullable', Rule::in([...config('events.categories'), $event->category])],
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

        $from = EventStatus::from($event->status);
        $to = EventStatus::from($data['status']);

        /*
         * The transition table decides, not this method.
         *
         * Without it, a cancelled event could be republished by sending the
         * same request that publishes a draft — and everybody holding a ticket
         * has already been told it is off, with some of them refunded.
         */
        if ($from !== $to && ! $from->canBecome($to)) {
            return response()->json([
                'message' => $from === EventStatus::Cancelled
                    ? 'A cancelled event cannot go back on sale. Copy it to a new date instead.'
                    : "An event that is {$from->label()} cannot become {$to->label()}.",
            ], 422);
        }

        if ($to === EventStatus::Draft) {
            $event->update(['status' => EventStatus::Draft->value]);

            // Taking an event off sale is not cancelling it, but it does stop
            // people buying — worth a record of who decided that and when.
            $this->auditor->record(
                'event.unpublished',
                $event,
                $request->user(),
                metadata: ['from' => $from->value],
            );

            return response()->json(['status' => EventStatus::Draft->value]);
        }

        $needsTickets = $event->kind === 'ticketed'
            && ! $event->ticketTypes()->where('status', 'on_sale')->exists();

        if ($needsTickets) {
            return response()->json([
                'message' => 'Add at least one ticket on sale before publishing.',
            ], 422);
        }

        // Publishing something that has already happened puts an event on the
        // front page that nobody can attend, and schedules reminders for a date
        // in the past.
        if ($event->starts_at->isPast()) {
            return response()->json([
                'message' => 'This event has already started. Change the date before publishing.',
            ], 422);
        }

        $event->update([
            'status' => 'published',
            // Kept from the first publish, so unpublishing and republishing
            // does not make an old event look newly announced.
            'published_at' => $event->published_at ?? now(),
        ]);

        $this->scheduleDefaultReminders($event);

        $this->auditor->record('event.published', $event, $request->user());

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
            // What buyers paid the platform on top of the ticket price. Shown
            // so an organizer can reconcile against what a buyer says they were
            // charged, and kept out of the net below because it was never taken
            // from them.
            'service_charge' => $money(
                (int) $event->orders()->where('status', 'paid')->sum('service_charge_amount')
            ),
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
     * Reminders an organizer did not have to think about.
     *
     * A week out to plan around, the day before to remember, and three hours
     * out for anyone who has not left yet. Created on first publish only —
     * firstOrCreate rather than create, so republishing does not resurrect a
     * reminder the organizer deliberately turned off, and so an event that was
     * taken down and put back does not send twice.
     *
     * Skipped entirely for anything starting sooner than the offset, since a
     * reminder for a moment already past is not something to write to the
     * database and then decline to send.
     */
    private function scheduleDefaultReminders(Event $event): void
    {
        foreach ([7 * 24 * 60, 24 * 60, 3 * 60] as $minutes) {
            if ($event->starts_at->copy()->subMinutes($minutes)->isPast()) {
                continue;
            }

            $event->reminders()->firstOrCreate(
                ['offset_minutes' => $minutes],
                ['status' => 'scheduled'],
            );
        }
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
