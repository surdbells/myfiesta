<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A stretch of days a report covers, counted in one market's clock.
 *
 * Whole days only, from the start of the first to the start of the day after
 * the last, so "the last 7 days" means today and the six before it wherever
 * the market is. The end is exclusive, which is what lets two neighbouring
 * periods share a boundary without counting the midnight sale twice.
 *
 * Each period knows the one before it — the same number of days, ending where
 * this one starts — because a figure with nothing to compare it to cannot say
 * whether it is good.
 */
final readonly class Period
{
    public const OPTIONS = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '12m' => 'Last 12 months',
        'ytd' => 'This year',
        'custom' => 'Custom range',
    ];

    public const DEFAULT = '30d';

    /** A custom range longer than this is cut to it: three years of days. */
    public const MAX_DAYS = 1096;

    public function __construct(
        public string $key,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public string $timezone,
    ) {}

    /**
     * The period a set of picker values describes.
     *
     * Anything unreadable falls back to the default rather than failing: a
     * bookmarked link with a mangled date should still open a report.
     */
    public static function resolve(
        ?string $key,
        ?string $from = null,
        ?string $until = null,
        string $timezone = 'UTC',
        ?CarbonImmutable $now = null,
    ): self {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $key = array_key_exists((string) $key, self::OPTIONS) ? (string) $key : self::DEFAULT;

        if ($key === 'custom') {
            $range = self::customRange($from, $until, $timezone);

            if ($range === null) {
                $key = self::DEFAULT;
            } else {
                return new self('custom', $range[0], $range[1], $timezone);
            }
        }

        [$start, $end] = match ($key) {
            '7d' => [$today->subDays(6), $today->addDay()],
            '90d' => [$today->subDays(89), $today->addDay()],
            '12m' => [$today->subYearNoOverflow()->addDay(), $today->addDay()],
            'ytd' => [$today->startOfYear(), $today->addDay()],
            default => [$today->subDays(29), $today->addDay()],
        };

        return new self($key, $start, $end, $timezone);
    }

    /** @param array<string, mixed>|null $filters */
    public static function fromFilters(?array $filters, string $timezone): self
    {
        return self::resolve(
            $filters['period'] ?? null,
            isset($filters['from']) ? (string) $filters['from'] : null,
            isset($filters['until']) ? (string) $filters['until'] : null,
            $timezone,
        );
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private static function customRange(?string $from, ?string $until, string $timezone): ?array
    {
        if (blank($from) || blank($until)) {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
            $last = CarbonImmutable::parse($until, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        if ($last->lt($start)) {
            [$start, $last] = [$last, $start];
        }

        if ($start->diffInDays($last) + 1 > self::MAX_DAYS) {
            $start = $last->subDays(self::MAX_DAYS - 1);
        }

        return [$start, $last->addDay()];
    }

    /** How many days the period covers. */
    public function days(): int
    {
        return (int) round($this->from->diffInDays($this->until));
    }

    /** The same number of days, ending where this one starts. */
    public function previous(): self
    {
        return new self($this->key, $this->from->subDays($this->days()), $this->from, $this->timezone);
    }

    /**
     * How finely a chart over this period is cut.
     *
     * Days up to a month, weeks up to a quarter, months beyond — roughly
     * thirty points at most, which is what fits across a chart and still
     * reads as a shape.
     */
    public function granularity(): string
    {
        return match (true) {
            $this->days() <= 31 => 'day',
            $this->days() <= 92 => 'week',
            default => 'month',
        };
    }

    /**
     * Every bucket start in the period, in the market's clock, as Y-m-d.
     *
     * Matches Postgres date_trunc: weeks start on Monday, months on the 1st.
     * Quiet buckets are listed too, so a chart shows a quiet week as quiet
     * rather than closing the gap.
     *
     * @return list<string>
     */
    public function buckets(): array
    {
        $cursor = $this->truncate($this->from);
        $buckets = [];

        while ($cursor->lt($this->until)) {
            $buckets[] = $cursor->toDateString();
            $cursor = match ($this->granularity()) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        return $buckets;
    }

    /** A bucket's name on an axis: "Sep 4", "Sep 1" (week of), "Sep 2026". */
    public function bucketLabel(string $bucket): string
    {
        $date = CarbonImmutable::parse($bucket, $this->timezone);

        return match ($this->granularity()) {
            'month' => $date->format('M Y'),
            default => $date->format('M j'),
        };
    }

    /** @return list<string> */
    public function bucketLabels(): array
    {
        return array_map(fn (string $bucket) => $this->bucketLabel($bucket), $this->buckets());
    }

    /** "1 Sep – 30 Sep 2026", the last day inclusive. */
    public function label(): string
    {
        $last = $this->until->subDay();

        return $this->from->year === $last->year
            ? $this->from->format('j M').' – '.$last->format('j M Y')
            : $this->from->format('j M Y').' – '.$last->format('j M Y');
    }

    /** Words for a comparison: "vs previous 30 days". */
    public function comparisonLabel(): string
    {
        return 'vs previous '.$this->days().' days';
    }

    /**
     * For a query binding. Laravel formats a bound date without its offset,
     * which Postgres would read as UTC; a string with the offset cannot be
     * misread.
     */
    public function fromSql(): string
    {
        return $this->from->utc()->format('Y-m-d H:i:sP');
    }

    public function untilSql(): string
    {
        return $this->until->utc()->format('Y-m-d H:i:sP');
    }

    public function cacheKey(): string
    {
        return $this->key.':'.$this->from->toDateString().':'.$this->until->toDateString().':'.$this->timezone;
    }

    private function truncate(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this->granularity()) {
            'day' => $date->startOfDay(),
            'week' => $date->startOfWeek(CarbonImmutable::MONDAY),
            default => $date->startOfMonth(),
        };
    }
}
