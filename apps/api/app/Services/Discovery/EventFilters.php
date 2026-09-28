<?php

namespace App\Services\Discovery;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final readonly class EventFilters
{
    /** The windows `when` takes. Each is read in the event's own zone (EventSearch). */
    public const WHEN = ['upcoming', 'today', 'weekend', 'month', 'past'];

    /**
     * @param  list<string>  $availability  states from Availability::STATES
     */
    public function __construct(
        public ?string $text = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $category = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $maxPrice = null,
        public bool $freeOnly = false,
        public string $sort = 'soonest',
        public int $perPage = 24,
        public ?string $when = null,
        /** A calendar day, YYYY-MM-DD, compared with each event's own date. */
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public array $availability = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $when = $request->string('when')->trim()->lower()->value();
        $when = in_array($when, self::WHEN, true) ? $when : null;

        return new self(
            text: $request->string('q')->trim()->value() ?: null,
            city: $request->string('city')->trim()->value() ?: null,
            country: $request->string('country')->trim()->value() ?: null,
            category: $request->string('category')->trim()->value() ?: null,
            from: $request->date('from')?->toDateTimeString(),
            to: $request->date('to')?->toDateTimeString(),
            // Minor units, matching every other amount in the system.
            maxPrice: $request->integer('max_price') ?: null,
            freeOnly: $request->boolean('free'),
            // Looking back defaults to the most recent night first: soonest
            // first over what has already happened answers with the oldest
            // event on the platform.
            sort: $request->string('sort')->value() ?: ($when === 'past' ? 'recent' : 'soonest'),
            // Capped so a crawler cannot ask for the whole table in one page.
            perPage: min(max($request->integer('per_page', 24), 1), 50),
            when: $when,
            dateFrom: self::day($request->string('date_from')->value()),
            dateTo: self::day($request->string('date_to')->value()),
            availability: self::states($request->string('availability')->value()),
        );
    }

    /** A calendar day as typed, or nothing: a date that does not parse filters nothing rather than erroring. */
    private static function day(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            // Written back and compared, because 2026-02-31 parses — as March.
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }

        return $day === $value ? $day : null;
    }

    /**
     * States, comma-separated. `on_sale` is what somebody ticking "can still
     * buy" means: anything but sold out or closed.
     *
     * @return list<string>
     */
    private static function states(string $value): array
    {
        $asked = array_filter(array_map('trim', explode(',', strtolower($value))));
        $states = [];

        foreach ($asked as $state) {
            if ($state === 'on_sale') {
                array_push($states, Availability::AVAILABLE, Availability::ALMOST_SOLD_OUT, Availability::UNLIMITED);
            } elseif (in_array($state, Availability::STATES, true)) {
                $states[] = $state;
            }
        }

        return array_values(array_unique($states));
    }
}
