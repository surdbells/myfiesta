<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventSeries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Throwable;

/**
 * Turning a rule into real events.
 *
 * A rolling window rather than everything at once. "Every Friday" has no end,
 * so occurrences are materialised a few months ahead and a scheduled job
 * extends the window — which also means an organizer who abandons a series
 * stops accumulating events shortly after they stop looking.
 *
 * Two properties this has to hold, and neither is free:
 *
 * It only ever creates. An occurrence that exists is never rewritten, because
 * the organizer may have edited it — moved one Friday to the Saturday, dropped
 * the price for a quiet week, changed the lineup. A generator that "keeps
 * occurrences in sync with the series" would undo all of that on a schedule,
 * and the organizer would never work out what was doing it.
 *
 * Running twice changes nothing. Slots already materialised are skipped by
 * their scheduled instant, not by their actual start — so an occurrence moved
 * to a different night is still recognised as done, and its Friday slot does
 * not get filled a second time. A unique index enforces this underneath, so
 * two overlapping runs cannot both win.
 */
class SeriesGenerator
{
    /** How far ahead to materialise. Roughly two quarters of Fridays. */
    public const HORIZON_DAYS = 180;

    /** A hard stop, in case a rule expands to something absurd. */
    private const MAX_PER_RUN = 60;

    public function __construct(private readonly EventDuplicator $duplicator) {}

    /**
     * Extend every active series that needs it.
     *
     * @return int occurrences created
     */
    public function generateAll(): int
    {
        $created = 0;

        foreach (EventSeries::where('status', 'active')->with('source')->cursor() as $series) {
            try {
                $created += count($this->generate($series));
            } catch (Throwable $e) {
                // One malformed rule must not stop every other series. The
                // failure is loud in the log and invisible to the other
                // organizers sharing this job.
                Log::error('Series generation failed', [
                    'series_id' => $series->id,
                    'rrule' => $series->rrule,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * Materialise the slots this series is missing.
     *
     * @return list<Event> newly created occurrences
     */
    public function generate(EventSeries $series, ?CarbonInterface $horizon = null): array
    {
        $horizon ??= CarbonImmutable::now()->addDays(self::HORIZON_DAYS);

        $slots = $this->slotsFor($series, $horizon);

        // Already materialised, matched on the scheduled instant rather than
        // the actual start — an occurrence the organizer moved is still done.
        $taken = $series->occurrences()
            ->pluck('series_occurs_at')
            ->map(fn ($at) => $at->getTimestamp())
            ->flip();

        $skipped = $series->exceptions()
            ->pluck('occurs_at')
            ->map(fn ($at) => $at->getTimestamp())
            ->flip();

        $created = [];

        foreach ($slots as $slot) {
            $key = $slot->getTimestamp();

            if ($taken->has($key) || $skipped->has($key)) {
                continue;
            }

            // The first occurrence is the source event itself, already built by
            // the organizer. Copying it onto its own slot would produce a
            // duplicate of the night they are looking at.
            if ($key === $series->starts_at->getTimestamp()) {
                continue;
            }

            $created[] = $this->materialise($series, $slot);

            if (count($created) >= self::MAX_PER_RUN) {
                break;
            }
        }

        $series->update(['generated_through' => $horizon]);

        return $created;
    }

    /**
     * The instants the rule produces, up to the horizon.
     *
     * Evaluated in the series' own zone, which is the venue's. This is the part
     * that has to be a library rather than arithmetic: "every Friday at 9pm"
     * must stay 9pm across a clock change, and adding seven days of seconds
     * moves the event by an hour twice a year, in opposite directions.
     *
     * @return list<\DateTimeInterface>
     */
    public function slotsFor(EventSeries $series, CarbonInterface $horizon): array
    {
        $zone = new DateTimeZone($series->timezone);

        $rule = new Rule(
            $series->rrule,
            // Handed to the library as the wall clock in the venue's zone, so
            // that is what it holds fixed.
            new DateTime($series->starts_at->timezone($series->timezone)->format('Y-m-d H:i:s'), $zone),
            null,
            $series->timezone,
        );

        $config = new ArrayTransformerConfig;
        // A rule with no COUNT or UNTIL is infinite; the transformer needs a
        // ceiling or it never returns.
        $config->setVirtualLimit(self::MAX_PER_RUN * 4);

        $transformer = new ArrayTransformer($config);

        $slots = [];

        foreach ($transformer->transform($rule) as $occurrence) {
            $start = $occurrence->getStart();

            if ($start > $horizon) {
                break;
            }

            $slots[] = $start;
        }

        return $slots;
    }

    /**
     * One occurrence, copied from the source.
     *
     * Wrapped so a duplicate slot loses a race rather than throwing: the unique
     * index is the real guarantee, and a second worker hitting it is a
     * non-event rather than an error worth waking anyone for.
     */
    private function materialise(EventSeries $series, \DateTimeInterface $slot): Event
    {
        return DB::transaction(function () use ($series, $slot) {
            $occurrence = $this->duplicator->duplicate(
                $series->source,
                CarbonImmutable::instance($slot),
            );

            $occurrence->update([
                'series_id' => $series->id,
                'series_occurs_at' => $slot,
            ]);

            return $occurrence->refresh();
        });
    }
}
