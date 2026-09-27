<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeries;
use App\Services\Events\SeriesGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;
use Recurr\Rule;
use Throwable;

/**
 * Making an event repeat, and stopping it.
 *
 * The rule is accepted as RFC 5545 because that is the interchange format —
 * the same string a calendar export carries — but the console never asks an
 * organizer to write one. It offers weekly, fortnightly and monthly, and turns
 * those into a rule here.
 */
class SeriesController extends Controller
{
    public function __construct(private readonly SeriesGenerator $generator) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('viewInConsole', $event);

        $series = $event->series;

        if (! $series) {
            return response()->json(['series' => null]);
        }

        return response()->json(['series' => $this->present($series)]);
    }

    /**
     * Turn an event into the first of a series.
     *
     * The event becomes the source: the shape future occurrences copy, and one
     * an organizer can look at and correct, rather than a hidden template.
     */
    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $this->authorize('create', [Event::class, $event->organization_id]);

        if ($event->series_id) {
            return response()->json([
                'message' => 'This event is already part of a series.',
            ], 422);
        }

        // Each occurrence is a copy with no takedown on it (EventModeration).
        if ($event->taken_down_at !== null) {
            return response()->json([
                'message' => 'myFiesta has taken this event off sale, so it cannot repeat until that is lifted.',
            ], 422);
        }

        $data = $request->validate([
            'frequency' => ['required', ValidationRule::in(['weekly', 'fortnightly', 'monthly'])],
            // Stops after this many, counting the one that already exists.
            // Null repeats indefinitely, materialised a window at a time.
            'count' => ['nullable', 'integer', 'min:2', 'max:104'],
            'until' => ['nullable', 'date', 'after:today'],
        ]);

        $rrule = $this->ruleFor($event, $data);

        try {
            // Parsed before it is stored. A rule that only fails at generation
            // time fails in a scheduled job at three in the morning, where
            // nobody is watching and the organizer just sees no events.
            new Rule($rrule, $event->starts_at->toDateTime(), null, $event->timezone);
        } catch (Throwable) {
            return response()->json(['message' => 'That repeat pattern could not be read.'], 422);
        }

        $series = EventSeries::create([
            'organization_id' => $event->organization_id,
            'source_event_id' => $event->id,
            'rrule' => $rrule,
            'timezone' => $event->timezone,
            'starts_at' => $event->starts_at,
            'status' => 'active',
        ]);

        // The source is itself the first occurrence, so it joins the series
        // rather than sitting outside it — otherwise the count is always one
        // short and cancelling the series leaves an orphan behind.
        $event->update([
            'series_id' => $series->id,
            'series_occurs_at' => $event->starts_at,
        ]);

        $created = $this->generator->generate($series->refresh());

        return response()->json([
            'series' => $this->present($series->refresh()),
            'created' => count($created),
        ], 201);
    }

    /**
     * Skip one date.
     *
     * Recorded as an exception as well as cancelled, because the generator runs
     * again — without the record it would put Boxing Day back a week later.
     */
    public function skip(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'occurrence_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        $series = $event->series;

        if (! $series) {
            return response()->json(['message' => 'This event does not repeat.'], 422);
        }

        $occurrence = $series->occurrences()->whereKey($data['occurrence_id'])->first();

        if (! $occurrence) {
            return response()->json(['message' => 'That date is not part of this series.'], 404);
        }

        if ($occurrence->tickets()->whereIn('status', ['valid', 'checked_in'])->exists()) {
            // Cancelling a night people hold tickets to is a refund
            // conversation, not a scheduling one, and doing it silently here
            // would leave those people with tickets to nothing.
            return response()->json([
                'message' => 'Tickets have been sold for that date. Refund them first.',
            ], 422);
        }

        $series->exceptions()->firstOrCreate(
            ['occurs_at' => $occurrence->series_occurs_at],
            ['reason' => $data['reason'] ?? null],
        );

        $occurrence->delete();

        return response()->json(['message' => 'That date has been taken out of the series.']);
    }

    /**
     * Stop repeating.
     *
     * Future occurrences that nobody has bought into are removed; anything
     * already sold stays exactly where it is. An organizer ending a residency
     * is not asking to cancel next Friday on the people who bought for it.
     */
    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $series = $event->series;

        if (! $series) {
            return response()->json(['message' => 'This event does not repeat.'], 422);
        }

        $removed = 0;

        foreach ($series->occurrences()->where('starts_at', '>', now())->get() as $occurrence) {
            if ($occurrence->id === $series->source_event_id) {
                continue;
            }

            if ($occurrence->tickets()->whereIn('status', ['valid', 'checked_in'])->exists()) {
                continue;
            }

            $occurrence->delete();
            $removed++;
        }

        $series->update(['status' => 'ended']);

        return response()->json([
            'message' => $removed === 0
                ? 'This event will not repeat again.'
                : "This event will not repeat again. {$removed} future dates were removed.",
        ]);
    }

    /**
     * A friendly choice, turned into the standard's grammar.
     *
     * BYDAY is pinned to the source's own weekday so "weekly" means the same
     * night rather than whatever the library infers, and monthly repeats on
     * the same weekday-of-month — the fourth Friday, not the 22nd — because
     * that is what a residency actually is.
     */
    private function ruleFor(Event $event, array $data): string
    {
        $local = $event->starts_at->timezone($event->timezone);
        $day = strtoupper(substr($local->format('D'), 0, 2));

        $parts = match ($data['frequency']) {
            'weekly' => ['FREQ=WEEKLY', "BYDAY={$day}"],
            'fortnightly' => ['FREQ=WEEKLY', 'INTERVAL=2', "BYDAY={$day}"],
            'monthly' => [
                'FREQ=MONTHLY',
                // Which occurrence of that weekday this date is: 1st, 2nd…
                'BYDAY='.(int) ceil($local->day / 7).$day,
            ],
        };

        if (! empty($data['count'])) {
            $parts[] = 'COUNT='.$data['count'];
        } elseif (! empty($data['until'])) {
            $parts[] = 'UNTIL='.CarbonImmutable::parse($data['until'])->utc()->format('Ymd\THis\Z');
        }

        return implode(';', $parts);
    }

    private function present(EventSeries $series): array
    {
        return [
            'id' => $series->id,
            'rrule' => $series->rrule,
            'status' => $series->status,
            'timezone' => $series->timezone,
            'generated_through' => $series->generated_through,
            'source_event_id' => $series->source_event_id,
            // Upcoming only, with a count of the rest. A weekly night that
            // has run for years has hundreds behind it, and each of those is
            // its own event in the list already; the series view is for
            // what is still ahead.
            'past_count' => $series->occurrences()->where('starts_at', '<=', now())->count(),
            'occurrences' => $series->occurrences()
                ->where('starts_at', '>', now())
                ->orderBy('series_occurs_at')
                ->get()
                ->map(fn (Event $e) => [
                    'id' => $e->id,
                    'slug' => $e->slug,
                    'title' => $e->title,
                    'starts_at' => $e->starts_at,
                    'series_occurs_at' => $e->series_occurs_at,
                    'status' => $e->status,
                    // Says whether the organizer has moved this one. A series
                    // where one night is on a Saturday should look different
                    // in the list, not identical to the ones that are not.
                    'moved' => ! $e->starts_at->equalTo($e->series_occurs_at),
                    'is_source' => $e->id === $series->source_event_id,
                ])->values(),
            'skipped' => $series->exceptions()
                ->where('occurs_at', '>', now())
                ->orderBy('occurs_at')
                ->get()
                ->map(fn ($x) => ['occurs_at' => $x->occurs_at, 'reason' => $x->reason])
                ->values(),
        ];
    }
}
