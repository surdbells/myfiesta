<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Services\Audit\Auditor;
use App\Services\Checkout\TurnedAway;
use App\Services\Events\EventCanceller;
use App\Services\Events\EventDuplicator;
use App\Services\Events\EventReviews;
use App\Services\Events\ReviewRefused;
use App\Services\Events\SalesReport;
use App\Services\Organizations\Suspension;
use App\Support\Listing;
use App\Support\Paging;
use App\Support\Search;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /** An order that stands: it was paid, and not all of it came back. */
    private const LIVE_ORDERS = ['paid', 'partially_refunded'];

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
        $filters = $request->validate([
            'when' => ['sometimes', 'in:upcoming,past'],
            'q' => ['nullable', 'string', 'max:120'],
            // Whole days in the reader's zone, both ends inclusive, as the
            // orders list reads them.
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
        ]);

        /*
         * Enough to judge a night by, without opening it.
         *
         * The list used to carry a title, a date and a count of tickets, which
         * is the same information a calendar has. What an organizer is
         * actually asking when they open this screen is which of these needs
         * them today — the one that is half sold with a week to go, the one
         * that has not sold since Tuesday, the one whose room is full. So the
         * row carries what sold, what it earned, how much of the room is
         * gone, how many looked, when the last ticket went, and how the last
         * fortnight went day by day.
         *
         * All of it as aggregates on the one query, and the fortnight in one
         * more for the page. A per-row lookup would be six more queries for an
         * organizer with thirty events, on the screen they open first.
         */
        $filtered = $this->filteredEvents($request, $filters);

        $query = $this->withInsights(clone $filtered);

        if ($request->filled('sort')) {
            Listing::sort($query, $request, self::SORTS, ['starts_at', 'asc']);
        } else {
            match ($filters['when'] ?? null) {
                'upcoming' => $query->orderBy('starts_at'),
                // Most recent first. The old combined order ran past events
                // oldest-first, so with enough history last week's night was
                // the one that fell off the end of the page.
                'past' => $query->orderByDesc('starts_at'),
                default => $query->orderByRaw('starts_at < now()')->orderBy('starts_at'),
            };
        }

        $events = $query->orderBy('id')->paginate(Paging::perPage($request, 30));

        $trends = $this->trends($events->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $events->getCollection()->map(fn (Event $e) => [
                'id' => $e->id,
                'slug' => $e->slug,
                'title' => $e->title,
                'kind' => $e->kind,
                'status' => $e->status,
                // A draft only because the platform suspended the organization
                // while it was on sale. It is not one the organizer forgot, and
                // it goes back on sale by itself when the suspension is lifted.
                'off_sale_by_suspension' => $e->unpublished_by_suspension_at !== null,
                'starts_at' => $e->starts_at,
                'timezone' => $e->timezone,
                'city' => $e->city,
                'currency' => $e->currency,
                ...$this->insights($request, $e),
                'trend' => $trends[$e->id] ?? $this->emptyTrend(),
                'poster_url' => $e->banner?->renditionUrl('thumb'),
            ])->values(),
            'meta' => Paging::meta($events),
            // The whole filtered set in a few figures, for the strip above the
            // list: what the page alone adds up to is a number about nothing.
            'summary' => $this->portfolio($request, $filtered),
            // Every city there is a night in, for the filter: a list of
            // what exists rather than a box to guess spellings into.
            'cities' => Event::query()
                ->whereIn('organization_id', $this->organizationIds($request))
                ->whereNotNull('city')
                ->distinct()
                ->orderBy('city')
                ->pluck('city')
                ->values(),
        ]);
    }

    /**
     * What a list of events can be sorted by, and what each is in SQL.
     *
     * Written out rather than read from the insight columns' aliases, which
     * Postgres will not let an expression refer to — and sell-through is an
     * expression: tickets out over the room, null where any tier has no
     * limit, so an unlimited night is neither full nor empty.
     */
    private const SORTS = [
        'starts_at' => 'events.starts_at',
        'title' => 'lower(events.title)',
        'sold' => "(select count(*) from tickets t where t.event_id = events.id and t.status in ('valid', 'checked_in'))",
        'revenue' => "(select coalesce(sum(o.net_revenue_amount), 0) from orders o where o.event_id = events.id and o.status in ('paid', 'partially_refunded'))",
        'sell_through' => "(case when exists (select 1 from ticket_types tt where tt.event_id = events.id and tt.deleted_at is null and tt.quantity_available is null) then null else (select count(*) from tickets t where t.event_id = events.id and t.status in ('valid', 'checked_in'))::float / nullif((select sum(tt.quantity_available) from ticket_types tt where tt.event_id = events.id and tt.deleted_at is null), 0) end)",
        'last_sale' => "(select max(o.paid_at) from orders o where o.event_id = events.id and o.status in ('paid', 'partially_refunded'))",
        'views' => '(select coalesce(sum(v.views + v.embed_views), 0) from event_views v where v.event_id = events.id)',
    ];

    /**
     * The events a list's filters describe, before insights, order or paging.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    private function filteredEvents(Request $request, array $filters): Builder
    {
        $statuses = Listing::many($request, 'status', array_map(fn (EventStatus $s) => $s->value, EventStatus::cases()));
        $cities = Listing::many($request, 'city', null, 50);
        $zone = $filters['timezone'] ?? 'UTC';

        return Event::query()
            ->whereIn('organization_id', $this->organizationIds($request))
            ->when(($filters['when'] ?? null) === 'upcoming', fn ($q) => $q->where('starts_at', '>=', now()))
            ->when(($filters['when'] ?? null) === 'past', fn ($q) => $q->where('starts_at', '<', now()))
            ->when($statuses, fn ($q, $values) => $q->whereIn('status', $values))
            ->when($cities, fn ($q, $values) => $q->whereIn('city', $values))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('starts_at', '>=', Carbon::parse($from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<=', Carbon::parse($to, $zone)->endOfDay()->utc()))
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters) {
                $like = Search::contains($filters['q']);
                $q->where(fn ($inner) => $inner->where('title', 'ilike', $like)->orWhere('city', 'ilike', $like));
            });
    }

    /** Days in a trend: two weeks, so this week can be read against the last. */
    private const TREND_DAYS = 14;

    /**
     * Tickets out per day over the last fortnight, for each event on the page.
     *
     * Counted from tickets rather than orders, so a comp handed out counts as
     * a place going, which is what a sell-through is about. One query for the
     * page. `momentum` is this week against the one before, as a share:
     * +0.4 is forty per cent more; null when nothing went out the week
     * before, because "up from nothing" is not a percentage.
     *
     * @param  list<string>  $ids
     * @return array<string, array{days: list<int>, this_week: int, last_week: int, momentum: float|null}>
     */
    private function trends(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $since = now()->startOfDay()->subDays(self::TREND_DAYS - 1);

        $rows = DB::table('tickets')
            ->whereIn('event_id', $ids)
            ->whereIn('status', ['valid', 'checked_in'])
            ->where('created_at', '>=', $since)
            ->selectRaw('event_id, (created_at::date) as day, count(*) as n')
            ->groupBy('event_id', 'day')
            ->get();

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = array_fill(0, self::TREND_DAYS, 0);
        }

        foreach ($rows as $row) {
            $offset = (int) $since->copy()->diffInDays(Carbon::parse($row->day)->startOfDay());

            if ($offset >= 0 && $offset < self::TREND_DAYS) {
                $out[$row->event_id][$offset] = (int) $row->n;
            }
        }

        return array_map(fn (array $days) => $this->trend($days), $out);
    }

    /**
     * @param  list<int>  $days
     * @return array{days: list<int>, this_week: int, last_week: int, momentum: float|null}
     */
    private function trend(array $days): array
    {
        $before = array_sum(array_slice($days, 0, 7));
        $recent = array_sum(array_slice($days, 7, 7));

        return [
            'days' => $days,
            'this_week' => $recent,
            'last_week' => $before,
            'momentum' => $before > 0 ? round(($recent - $before) / $before, 2) : null,
        ];
    }

    /** @return array{days: list<int>, this_week: int, last_week: int, momentum: float|null} */
    private function emptyTrend(): array
    {
        return $this->trend(array_fill(0, self::TREND_DAYS, 0));
    }

    /**
     * The filtered set in a few figures.
     *
     * Money is per currency and withheld from somebody who may not see it;
     * summed across two currencies it would be a number that is true of
     * nothing. "Stalled" is a night still to come that has sold before and
     * nothing in the last seven days; "nearly gone" is one with nine in ten
     * places taken. Both are the nights somebody should look at today.
     *
     * @param  Builder<Event>  $filtered
     * @return array<string, mixed>
     */
    private function portfolio(Request $request, Builder $filtered): array
    {
        $rows = $this->withInsights(clone $filtered)
            ->reorder()
            ->get();

        $maySeeMoney = collect($this->organizationIds($request))
            ->every(fn (string $id) => $request->user()->hasPermissionIn($id, Permission::MoneyView));

        $upcoming = $rows->filter(fn (Event $e) => $e->starts_at !== null && $e->starts_at->isFuture());
        $weekAgo = now()->subDays(7);

        $limited = $rows->filter(fn (Event $e) => (int) $e->unlimited_tiers === 0 && (int) $e->capacity > 0);

        return [
            'events' => $rows->count(),
            'upcoming' => $upcoming->count(),
            'tickets_issued' => (int) $rows->sum('tickets_issued'),
            'checked_in' => (int) $rows->sum('checked_in'),
            'orders' => (int) $rows->sum('orders_count'),
            // Places taken across the nights that have a limit, as a share of
            // those places: nights with no limit are left out of both sides.
            'sell_through' => $limited->sum('capacity') > 0
                ? round($limited->sum('tickets_issued') / $limited->sum('capacity'), 3)
                : null,
            'revenue' => $maySeeMoney
                ? $rows->groupBy('currency')
                    ->map(fn ($events, $currency) => ['amount' => (int) $events->sum('revenue_amount'), 'currency' => $currency])
                    ->values()
                : null,
            'stalled' => $upcoming
                ->filter(fn (Event $e) => (int) $e->tickets_issued > 0
                    && $e->last_sale_at !== null
                    && Carbon::parse($e->last_sale_at)->lessThan($weekAgo))
                ->count(),
            'nearly_sold_out' => $upcoming
                ->filter(fn (Event $e) => (int) $e->unlimited_tiers === 0
                    && (int) $e->capacity > 0
                    && (int) $e->tickets_issued / (int) $e->capacity >= 0.9)
                ->count(),
        ];
    }

    /**
     * Enough to judge a night by, as aggregates on the query that fetches it.
     *
     * What sold, what it earned, how much of the room is gone, how many
     * looked, and when the last ticket went — for the list and for one event
     * alike, from this one place, so the two cannot disagree about what "sold"
     * means.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function withInsights(Builder $query): Builder
    {
        return $query
            ->withCount([
                'tickets as tickets_issued' => fn ($q) => $q->whereIn('status', ['valid', 'checked_in']),
                'tickets as checked_in' => fn ($q) => $q->where('status', 'checked_in'),
                'orders as orders_count' => fn ($q) => $q->whereIn('status', self::LIVE_ORDERS),
                // A tier with no limit makes the whole room unlimited, and a
                // capacity bar drawn without knowing that is a lie.
                'ticketTypes as unlimited_tiers' => fn ($q) => $q->whereNull('quantity_available'),
            ])
            ->withSum(
                ['orders as revenue_amount' => fn ($q) => $q->whereIn('status', self::LIVE_ORDERS)],
                'net_revenue_amount',
            )
            ->withSum('ticketTypes as capacity', 'quantity_available')
            // When the last one sold. "Nothing since Tuesday" is the signal an
            // organizer acts on, and it is invisible in a total.
            ->withMax(
                ['orders as last_sale_at' => fn ($q) => $q->whereIn('status', self::LIVE_ORDERS)],
                'paid_at',
            )
            // The poster. A list of nights is a list of posters in an
            // organizer's head, and a row of text is slower to find the right
            // one in than a picture they chose themselves.
            ->with(['banner'])
            ->addSelect([
                'views' => DB::table('event_views')
                    ->selectRaw('coalesce(sum(views + embed_views), 0)')
                    ->whereColumn('event_views.event_id', 'events.id'),
            ]);
    }

    /**
     * The counted fields, as the list and the single event both send them.
     *
     * Earnings are null, not zero, for somebody who may not see money in the
     * event's organization — the rule the overview already follows. A door
     * member of the team needs to know how full the room is, not what it took.
     *
     * @return array<string, mixed>
     */
    private function insights(Request $request, Event $e): array
    {
        $maySeeMoney = $request->user()->hasPermissionIn($e->organization_id, Permission::MoneyView);

        return [
            'tickets_issued' => (int) $e->tickets_issued,
            'checked_in' => (int) $e->checked_in,
            'orders' => (int) $e->orders_count,
            // Null where any tier is unlimited: there is no proportion of an
            // unlimited room, and drawing one full or empty is worse than
            // drawing none.
            'capacity' => $e->unlimited_tiers > 0 ? null : (int) $e->capacity,
            'revenue' => $maySeeMoney ? ['amount' => (int) $e->revenue_amount, 'currency' => $e->currency] : null,
            'views' => (int) $e->views,
            'last_sale_at' => $e->last_sale_at ? Carbon::parse($e->last_sale_at)->toIso8601String() : null,
        ];
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
     *
     * It carries the list's counts as well. The shape said it did — the type
     * both clients read extends the list row — and it did not, which nothing
     * noticed until a screen showed how a night was going from this endpoint
     * and read undefined for every figure.
     */
    public function show(Request $request, Event $event, EventReviews $reviews): JsonResponse
    {
        $this->authorize('viewInConsole', $event);

        $counted = $this->withInsights(Event::query()->whereKey($event->id))->firstOrFail();

        return response()->json([
            ...$this->insights($request, $counted),
            'id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title,
            'kind' => $event->kind,
            'status' => $event->status,
            'off_sale_by_suspension' => $event->unpublished_by_suspension_at !== null,
            'description' => $event->description,
            'currency' => $event->currency,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            // Over by its own listing, when online sales stop: a night weeks
            // gone is not "On sale", and there is nothing left to take off sale.
            'sales_ended' => TurnedAway::pastSelling($event, now()),
            'timezone' => $event->timezone,
            'city' => $event->city,
            'subdivision' => $event->subdivision,
            'country' => $event->country,
            'category' => $event->category,
            'min_age' => $event->min_age,
            'id_required' => $event->id_required,
            'resale_enabled' => (bool) $event->resale_enabled,
            'resale_closes_hours' => (int) $event->resale_closes_hours,
            'poster_url' => $event->banner?->renditionUrl('display'),
            'review' => $this->reviewOf($event, $reviews),
        ]);
    }

    /**
     * Where the event stands with myFiesta's review, as the console shows it.
     *
     * `on_submit` answers the question the button has to answer before it is
     * pressed: will this go straight back on sale, or into the queue? And the
     * reason it was last sent back stays on the event until it is sent again,
     * so the organizer is not left to find it in an email.
     *
     * @return array<string, mixed>
     */
    private function reviewOf(Event $event, EventReviews $reviews): array
    {
        $rejection = $reviews->standingRejection($event);

        return [
            'submitted_at' => $event->submitted_at?->toIso8601String(),
            'approved_at' => $event->approved_at?->toIso8601String(),
            'on_submit' => $reviews->whatSubmittingDoes($event),
            // Nothing of a suspended organization's can be sent or put back
            // on sale (EventReviews::submit), so no screen offers to.
            'suspended' => Suspension::inForce($event->organization_id),
            // For an event on sale: whether taking it off and putting it back
            // would go straight back on sale, said before it is taken off.
            'unchanged_since_approval' => $reviews->unchangedSinceApproval($event),
            'not_ready' => $event->status === EventStatus::Draft->value ? $reviews->notReadyBecause($event) : [],
            'rejection' => $rejection === null ? null : [
                'reason' => $rejection->reason,
                'at' => $rejection->created_at?->toIso8601String(),
            ],
            'history' => $reviews->historyFor($event),
        ];
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
        $this->authorize('viewInConsole', $event);
        $this->authorize('create', [Event::class, $event->organization_id]);

        // A copy would carry no takedown, and publish() only refuses the
        // event that does: copying is the takedown undone in two calls.
        if ($event->taken_down_at !== null) {
            return response()->json([
                'message' => 'myFiesta has taken this event off sale, so it cannot be copied until that is lifted. '
                    .'Reply to the email we sent to have it looked at again.',
            ], 422);
        }

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

        // What staff are looking at has to be what goes on sale.
        EventReviews::refuseWhileInReview($event);

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
            // Letting people hand tickets back. Off unless it is turned on:
            // it changes who is in the room, which is the organizer's call.
            'resale_enabled' => ['sometimes', 'boolean'],
            'resale_closes_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
        ]);

        $before = $event->only(array_keys($data));

        // Currency is absent on purpose. Changing it after a sale would leave
        // orders denominated in one currency under an event claiming another,
        // and the ledger has no way to express that.
        $event->update($data);

        /*
         * Changed while on sale: allowed without another review, and kept on
         * the record. An approval is of what the event said then, and an edit
         * afterwards is the organizer's word alone — this is where somebody
         * asking "who moved the date?" finds out. It also means the event is
         * no longer what was approved, so taking it off sale and putting it
         * back sends it through review.
         */
        $changed = array_values(array_diff(array_keys($event->getChanges()), ['updated_at', 'search_vector']));

        if ($changed !== [] && $event->status === EventStatus::Published->value) {
            $shown = array_diff($changed, ['description']);

            $this->auditor->record('event.edited_on_sale', $event, $request->user(), metadata: [
                'changed' => $changed,
                'before' => array_intersect_key($before, array_flip($shown)),
                'after' => $event->only($shown),
            ]);
        }

        return response()->json(
            new EventResource($event->fresh()->load(['organization', 'ticketTypes'])),
        );
    }

    /**
     * Send for review, or take off sale.
     *
     * Kept for the clients that already call it. `published` is the same as
     * submit(): nothing goes on sale without an approval, so asking for it
     * sends the event for review, or straight back on sale when an approval
     * still stands for it as it is. `draft` takes it off sale — or, while it
     * is waiting, takes it back from review.
     */
    public function publish(Request $request, Event $event, EventReviews $reviews): JsonResponse
    {
        $this->authorize('publish', $event);

        $data = $request->validate([
            'status' => ['required', 'in:draft,published'],
        ]);

        if ($data['status'] === EventStatus::Published->value) {
            return $this->answer(fn () => $reviews->submit($event, $request->user()));
        }

        $from = EventStatus::from($event->status);

        if ($from === EventStatus::InReview) {
            return $this->answer(fn () => $reviews->withdraw($event, $request->user()));
        }

        /*
         * The transition table decides, not this method.
         *
         * Without it, a cancelled event could be put back to a draft and then
         * on sale — and everybody holding a ticket has already been told it is
         * off, with some of them refunded.
         */
        if ($from !== EventStatus::Draft && ! $from->canBecome(EventStatus::Draft)) {
            return response()->json([
                'message' => $from === EventStatus::Cancelled
                    ? 'A cancelled event cannot go back on sale. Copy it to a new date instead.'
                    : "An event that is {$from->label()} cannot become a draft.",
            ], 422);
        }

        $event->update(['status' => EventStatus::Draft->value]);

        // Off sale already because the organization is suspended, and now
        // because the organizer says so too. Theirs is the decision that
        // outlasts the suspension: without the mark, lifting it leaves this
        // one a draft instead of putting it back on sale (Suspension).
        $keptOff = $event->unpublished_by_suspension_at !== null;

        if ($keptOff) {
            $event->forceFill(['unpublished_by_suspension_at' => null, 'unpublished_by_suspension_fingerprint' => null])->save();
        }

        // Taking an event off sale is not cancelling it, but it does stop
        // people buying — worth a record of who decided that and when.
        $this->auditor->record(
            'event.unpublished',
            $event,
            $request->user(),
            metadata: ['from' => $from->value] + ($keptOff ? ['kept_off_after_suspension' => true] : []),
        );

        return response()->json([
            'status' => EventStatus::Draft->value,
            'outcome' => 'unpublished',
            // Said now, so putting it back holds no surprise later — and only
            // when submit() would then do it.
            'message' => $reviews->whatSubmittingDoes($event) === 'publish' && $reviews->notReadyBecause($event) === []
                ? 'Taken off sale. Nothing has changed since it was approved, so you can put it back on sale without another review.'
                : 'Taken off sale.',
        ]);
    }

    /**
     * Send a draft to myFiesta to be looked at.
     *
     * Needs what publishing needed: the permission, a proved address
     * (verified.email on the route), and an event that is ready — something on
     * sale, a date to come, a description and a place. Every reason it is not
     * comes back at once. Where an approval still stands for the event as it
     * is — taken off sale and unchanged, or the approved night of a series on
     * a new date — it goes straight on sale instead, and the answer says so.
     */
    public function submit(Request $request, Event $event, EventReviews $reviews): JsonResponse
    {
        $this->authorize('publish', $event);

        return $this->answer(fn () => $reviews->submit($event, $request->user()));
    }

    /** Take an event back from review, to change something. */
    public function withdraw(Request $request, Event $event, EventReviews $reviews): JsonResponse
    {
        $this->authorize('publish', $event);

        return $this->answer(fn () => $reviews->withdraw($event, $request->user()));
    }

    /** @param  \Closure(): array<string, mixed>  $step */
    private function answer(\Closure $step): JsonResponse
    {
        try {
            return response()->json($step());
        } catch (ReviewRefused $refused) {
            return response()->json($refused->body(), $refused->status);
        }
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

    /** Sales by day, by ticket type and by code. See SalesReport for what each counts. */
    public function sales(Event $event, SalesReport $report): JsonResponse
    {
        $this->authorize('viewSales', $event);

        return response()->json($report->for($event));
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

        while (Event::slugIsTaken($slug)) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
