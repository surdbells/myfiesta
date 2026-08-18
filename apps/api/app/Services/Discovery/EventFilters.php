<?php

namespace App\Services\Discovery;

use Illuminate\Http\Request;

final readonly class EventFilters
{
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
    ) {}

    public static function fromRequest(Request $request): self
    {
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
            sort: $request->string('sort')->value() ?: 'soonest',
            // Capped so a crawler cannot ask for the whole table in one page.
            perPage: min(max($request->integer('per_page', 24), 1), 50),
        );
    }
}
