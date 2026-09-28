<?php

namespace App\Services\Discovery;

use App\Models\Event;
use App\Services\Checkout\TurnedAway;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Today, this weekend, later, this month and before now — each in the
 * event's own zone.
 *
 * A night in Lagos is on Saturday for the people going to it, whoever is
 * reading. Worked out in the reader's zone, or in UTC, a 10pm Friday party in
 * Lagos is a Friday-night thing at 4pm in Toronto and a Saturday one to the
 * server, and "this weekend" picks it up or drops it by accident.
 *
 * A night counts as happening until it is over by its own listing — its end,
 * or half a day after it starts when it has none (TurnedAway::listedEnd) — so
 * the festival that opened at noon is still "today" at six, which is when
 * somebody is deciding whether to go.
 */
final class EventWindows
{
    /** The event's own calendar day. */
    private const LOCAL_DAY = '(events.starts_at at time zone events.timezone)::date';

    /** Today, where the event is. One binding: now. */
    private const LOCAL_TODAY = '(?::timestamptz at time zone events.timezone)::date';

    /** Monday is 1 and Sunday 7, where the event is. One binding: now. */
    private const LOCAL_WEEKDAY = 'extract(isodow from (?::timestamptz at time zone events.timezone))::int';

    /** When the night is over by its own listing, as SQL over `events`. */
    public static function endSql(): string
    {
        $hours = TurnedAway::HOURS_WITHOUT_AN_END;

        return "coalesce(events.ends_at, events.starts_at + interval '{$hours} hours')";
    }

    /**
     * Narrow to one window.
     *
     * @param  Builder<Event>  $query
     */
    public static function apply(Builder $query, string $window, CarbonInterface $now): void
    {
        match ($window) {
            // Not over, and started today or before — which is what is on now
            // and what is still to come today.
            'today' => self::notOver($query, $now)
                ->whereRaw(self::LOCAL_DAY.' <= '.self::LOCAL_TODAY, [$now]),

            // Friday to Sunday: the one underway, or the next. Friday is today
            // plus (5 - weekday) days, which is also right on a Saturday (-1)
            // and a Sunday (-2).
            'weekend' => self::notOver($query, $now)->whereRaw(
                self::LOCAL_DAY.' between '
                .self::LOCAL_TODAY.' + (5 - '.self::LOCAL_WEEKDAY.') and '
                .self::LOCAL_TODAY.' + (7 - '.self::LOCAL_WEEKDAY.')',
                [$now, $now, $now, $now],
            ),

            // Still to come, after today and outside this weekend: what the
            // front page's "Coming up" holds once tonight and the weekend have
            // shelves of their own. Otherwise a busy weekend fills the first
            // dozen nights and the shelf below it shows the same ones again.
            'later' => $query->where('events.starts_at', '>', $now)
                ->whereRaw(self::LOCAL_DAY.' > '.self::LOCAL_TODAY, [$now])
                ->whereRaw(
                    'not ('.self::LOCAL_DAY.' between '
                    .self::LOCAL_TODAY.' + (5 - '.self::LOCAL_WEEKDAY.') and '
                    .self::LOCAL_TODAY.' + (7 - '.self::LOCAL_WEEKDAY.'))',
                    [$now, $now, $now, $now],
                ),

            'month' => self::notOver($query, $now)->whereRaw(
                "date_trunc('month', events.starts_at at time zone events.timezone)"
                ." = date_trunc('month', ?::timestamptz at time zone events.timezone)",
                [$now],
            ),

            'past' => $query->whereRaw(self::endSql().' <= ?', [$now]),

            default => $query->where('events.starts_at', '>=', $now),
        };
    }

    /**
     * Still happening or still to come.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public static function notOver(Builder $query, CarbonInterface $now): Builder
    {
        return $query->whereRaw(self::endSql().' > ?', [$now]);
    }

    /**
     * From or to a calendar day, as the event's own calendar has it.
     *
     * @param  Builder<Event>  $query
     */
    public static function days(Builder $query, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->whereRaw(self::LOCAL_DAY.' >= ?::date', [$from]);
        }

        if ($to !== null) {
            $query->whereRaw(self::LOCAL_DAY.' <= ?::date', [$to]);
        }
    }
}
