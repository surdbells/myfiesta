<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeries;
use App\Services\Events\EventReviews;
use App\Services\Events\SeriesGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;
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
    /**
     * A ticket somebody holds: valid, used, or listed to be sold on. A
     * listed ticket is still its holder's until somebody buys it, so a date
     * with one has been bought into like any other.
     */
    public const HELD = ['valid', 'checked_in', 'listed'];

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
        EventReviews::refuseWhileInReview($event);

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
        EventReviews::refuseWhileInReview($event);

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

        if ($occurrence->tickets()->whereIn('status', self::HELD)->exists()) {
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
        EventReviews::refuseWhileInReview($event);

        $series = $event->series;

        if (! $series) {
            return response()->json(['message' => 'This event does not repeat.'], 422);
        }

        $removed = $this->removeUnsold($series, fn (Event $occurrence) => true)['removed'];

        // Nothing left of it puts itself on sale: a date kept because it was
        // bought into is the organizer's own from here.
        $series->update(['status' => 'ended', 'auto_publish' => false, 'auto_publish_by' => null]);
        $this->unscheduleDates($series);

        return response()->json([
            'message' => $removed === 0
                ? 'This event will not repeat again.'
                : "This event will not repeat again. {$removed} future dates were removed.",
        ]);
    }

    /**
     * Change how a series ends, and whether its dates put themselves on sale.
     *
     * The end can move either way. Shortened, every date still to come past
     * the new end that nobody has bought into is removed, by stopping's rule
     * (destroy); one somebody holds a ticket for is kept where it is. Made
     * longer, the dates newly in the window are made now.
     *
     * How often it repeats does not change. Every date already made, and
     * every ticket for them, was made for the old pattern, and moving them
     * all is a different decision from extending a run: that is stopping
     * this series and starting another.
     *
     * Putting dates on sale by themselves is putting events on sale, so it
     * takes the permission and the proved address that sending one does, and
     * each date goes as the member who turned it on, asked again when it
     * goes (ScheduledGoLive). Turned on, the dates already made are given
     * their time too; turned off, the times the series gave them are taken
     * back, and a time somebody set on a date of their own is left.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        EventReviews::refuseWhileInReview($event);

        $series = $event->series;

        if (! $series) {
            return response()->json(['message' => 'This event does not repeat.'], 422);
        }

        if (! $series->isActive()) {
            return response()->json(['message' => 'This event no longer repeats. Copy it to start a new series.'], 422);
        }

        $data = $request->validate([
            'frequency' => ['sometimes', ValidationRule::in(['weekly', 'fortnightly', 'monthly'])],
            'count' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:104', 'prohibits:until'],
            'until' => ['sometimes', 'nullable', 'date'],
            'auto_publish' => ['sometimes', 'boolean'],
            // A day at least: a date sent at its own start is already over.
            'on_sale_days_before' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        if (isset($data['frequency']) && $data['frequency'] !== $series->frequency()) {
            throw ValidationException::withMessages(['frequency' => 'Stop this series and start a new one.']);
        }

        $publishing = array_key_exists('auto_publish', $data) || array_key_exists('on_sale_days_before', $data);

        if ($publishing) {
            $this->authorize('publish', $event);

            if (($data['auto_publish'] ?? $series->auto_publish) && $request->user()->email_verified_at === null) {
                return response()->json([
                    'message' => "Confirm your email address first. Open the link we sent to {$request->user()->email}, then try again.",
                    'code' => 'email_unverified',
                ], 403);
            }
        }

        $ending = array_key_exists('count', $data) || array_key_exists('until', $data);
        $rule = $series->rrule;

        if ($ending) {
            if (! empty($data['until'])) {
                $last = $this->lastDay((string) $data['until'], $series->timezone);

                if ($last->isPast()) {
                    throw ValidationException::withMessages(['until' => 'Choose a day still to come.']);
                }

                if ($last->lessThan($series->starts_at)) {
                    throw ValidationException::withMessages(['until' => 'Choose a day after the first date.']);
                }
            }

            $kept = array_filter(
                explode(';', $series->rrule),
                fn (string $part) => ! preg_match('/^(COUNT|UNTIL)=/i', $part),
            );

            $rule = implode(';', [...$kept, ...$this->endParts($data, $series->timezone)]);

            try {
                new Rule($rule, $series->starts_at->toDateTime(), null, $series->timezone);
            } catch (Throwable) {
                return response()->json(['message' => 'That end could not be read.'], 422);
            }
        }

        $wasOn = (bool) $series->auto_publish;
        $daysBefore = array_key_exists('on_sale_days_before', $data) ? $data['on_sale_days_before'] : $series->on_sale_days_before;
        $on = (bool) ($data['auto_publish'] ?? $series->auto_publish);

        $outcome = DB::transaction(function () use ($series, $rule, $ending, $publishing, $on, $daysBefore, $request) {
            $series->forceFill([
                'rrule' => $rule,
                'auto_publish' => $on,
                'on_sale_days_before' => $daysBefore,
                // Whoever last decided it goes on sale by itself is who it
                // goes as.
                'auto_publish_by' => $on ? ($publishing ? $request->user()->id : $series->auto_publish_by) : null,
            ])->save();

            $past = ['removed' => 0, 'kept' => 0];

            if ($ending) {
                $end = $this->lastSlot($series);

                if ($end !== null) {
                    $past = $this->removeUnsold($series, fn (Event $occurrence) => $occurrence->series_occurs_at->greaterThan($end));
                }
            }

            if ($publishing) {
                $on ? $this->scheduleDates($series) : $this->unscheduleDates($series);
            }

            return $past;
        });

        // Made longer: the dates newly inside the window, now rather than
        // overnight. A shorter rule makes nothing.
        $created = $ending ? count($this->generator->generate($series->refresh())) : 0;

        return response()->json([
            'series' => $this->present($series->refresh()),
            'removed' => $outcome['removed'],
            'kept' => $outcome['kept'],
            'created' => $created,
            'message' => $this->saidOf($series, $ending, $publishing, $wasOn, $outcome, $created),
        ]);
    }

    /**
     * The last instant the rule now produces, or null for one with no end.
     *
     * Counted out to the furthest date already made, which is as far as
     * anything could need removing.
     */
    private function lastSlot(EventSeries $series): ?CarbonImmutable
    {
        $parts = $series->ruleParts();

        if (! isset($parts['COUNT']) && ! isset($parts['UNTIL'])) {
            return null;
        }

        $furthest = $series->occurrences()->max('series_occurs_at');
        $horizon = CarbonImmutable::parse($furthest ?? $series->starts_at, 'UTC')->addDay();

        $slots = $this->generator->slotsFor($series, $horizon);
        $last = end($slots);

        return $last === false ? CarbonImmutable::instance($series->starts_at) : CarbonImmutable::instance($last);
    }

    /**
     * Remove the dates still to come that $past picks and nobody has bought
     * into. Never the source, which is the night the organizer built.
     *
     * @param  \Closure(Event): bool  $past
     * @return array{removed: int, kept: int}
     */
    private function removeUnsold(EventSeries $series, \Closure $past): array
    {
        $removed = 0;
        $kept = 0;

        foreach ($series->occurrences()->where('starts_at', '>', now())->get() as $occurrence) {
            if ($occurrence->id === $series->source_event_id || ! $past($occurrence)) {
                continue;
            }

            if ($occurrence->tickets()->whereIn('status', self::HELD)->exists()) {
                $kept++;

                continue;
            }

            $occurrence->delete();
            $removed++;
        }

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * Give each draft date still to come its time to go on sale, by the
     * series' rule: its own night less the days chosen (EventSeries::onSaleAt).
     * A date whose time somebody set on it is theirs and kept; the source is
     * the organizer's own to send.
     *
     * Only dates that have never been on sale, and that staff have not sent
     * back. A date the organizer took off sale is theirs to put back — given
     * a time, its approval would put it straight back on sale. One sent back
     * waits for the organizer to change it; sent again at a time, the same
     * thing would only be in front of staff again (EventReviews::reject).
     */
    private function scheduleDates(EventSeries $series): void
    {
        $reviews = app(EventReviews::class);

        $dates = $series->occurrences()
            ->where('status', EventStatus::Draft->value)
            ->where('starts_at', '>', now())
            ->whereNull('published_at')
            ->whereNull('taken_down_at')
            ->whereNull('publish_scheduled_by')
            ->whereKeyNot($series->source_event_id)
            ->get();

        foreach ($dates as $date) {
            if ($reviews->standingRejection($date) !== null) {
                continue;
            }

            // On the venue's calendar (EventSeries::onSaleAt), as the dates
            // made later are.
            $date->forceFill(['publish_at' => $series->onSaleAt($date->starts_at)])->save();
        }
    }

    /** Take back the times the series gave its drafts. A time a member set stays. */
    private function unscheduleDates(EventSeries $series): void
    {
        Event::query()
            ->where('series_id', $series->id)
            ->where('status', EventStatus::Draft->value)
            ->whereNull('publish_scheduled_by')
            ->whereNotNull('publish_at')
            ->update(['publish_at' => null]);
    }

    /**
     * What changed, as the organizer reads it.
     *
     * @param  array{removed: int, kept: int}  $past
     */
    private function saidOf(EventSeries $series, bool $ending, bool $publishing, bool $wasOn, array $past, int $created): string
    {
        $said = [];

        if ($ending) {
            $parts = $series->ruleParts();

            $said[] = match (true) {
                isset($parts['COUNT']) => "It now runs for {$parts['COUNT']} dates.",
                isset($parts['UNTIL']) => 'It now runs until '
                    .CarbonImmutable::parse($parts['UNTIL'])->timezone($series->timezone)->format('l j F Y').'.',
                default => 'It now repeats until you stop it.',
            };

            if ($past['removed'] > 0) {
                $said[] = $past['removed'] === 1 ? '1 date after that was removed.' : "{$past['removed']} dates after that were removed.";
            }

            if ($past['kept'] > 0) {
                $said[] = $past['kept'] === 1
                    ? '1 date after that is kept, because people hold tickets for it.'
                    : "{$past['kept']} dates after that are kept, because people hold tickets for them.";
            }

            if ($created > 0) {
                $said[] = $created === 1 ? '1 more date was added.' : "{$created} more dates were added.";
            }
        }

        if ($publishing) {
            $said[] = match (true) {
                ! $series->auto_publish => $wasOn ? 'Its dates no longer go on sale by themselves.' : 'Its dates do not go on sale by themselves.',
                $series->on_sale_days_before === null => 'Each date goes on sale by itself as soon as it is added.',
                default => 'Each date goes on sale by itself '
                    .($series->on_sale_days_before === 1 ? '1 day' : "{$series->on_sale_days_before} days").' before it.',
            };
        }

        return $said === [] ? 'Nothing changed.' : implode(' ', $said);
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

        return implode(';', [...$parts, ...$this->endParts($data, $event->timezone)]);
    }

    /**
     * How a rule ends: after so many dates, counting the first, or on a day
     * — the whole of that day in the venue's zone, so "until 30 October"
     * keeps a night at 9pm on the 30th — or not at all.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function endParts(array $data, string $timezone): array
    {
        if (! empty($data['count'])) {
            return ['COUNT='.$data['count']];
        }

        if (! empty($data['until'])) {
            return ['UNTIL='.$this->lastDay((string) $data['until'], $timezone)->utc()->format('Ymd\THis\Z')];
        }

        return [];
    }

    /** The end of a day the organizer named, in the venue's zone. */
    private function lastDay(string $until, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(substr($until, 0, 10), $timezone)->endOfDay();
    }

    private function present(EventSeries $series): array
    {
        $parts = $series->ruleParts();

        return [
            'id' => $series->id,
            'rrule' => $series->rrule,
            'status' => $series->status,
            'timezone' => $series->timezone,
            'generated_through' => $series->generated_through,
            'source_event_id' => $series->source_event_id,
            // The rule as the console offers it, so the settings show what
            // was chosen rather than the standard's grammar.
            'frequency' => $series->frequency(),
            'count' => isset($parts['COUNT']) ? (int) $parts['COUNT'] : null,
            // The last day, in the venue's zone, as it was typed.
            'until' => isset($parts['UNTIL'])
                ? CarbonImmutable::parse($parts['UNTIL'])->timezone($series->timezone)->format('Y-m-d')
                : null,
            'auto_publish' => (bool) $series->auto_publish,
            'on_sale_days_before' => $series->on_sale_days_before,
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
                    // When a draft goes on sale by itself, if it does.
                    'publish_at' => $e->publish_at?->toIso8601String(),
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
