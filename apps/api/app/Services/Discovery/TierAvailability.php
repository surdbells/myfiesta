<?php

namespace App\Services\Discovery;

/**
 * How one ticket type is selling, as a buyer is told it.
 *
 * `remaining` is the exact count and never leaves the API as it is: toArray()
 * names it only when it is small enough for the admin's setting, so the page
 * can say "Only 4 left" and cannot say "312 left" — which would be an
 * organizer's sales, readable by anybody refreshing the page.
 */
final readonly class TierAvailability
{
    public function __construct(
        public string $state,
        /** Places left now, holds counted. Null when the tier has no limit, or can no longer be bought. */
        public ?int $remaining,
        public ?int $capacity,
    ) {}

    public function soldOut(): bool
    {
        return $this->state === Availability::SOLD_OUT;
    }

    /** @return array{state: string, left: int|null} */
    public function toArray(Scarcity $scarcity): array
    {
        return [
            'state' => $this->state,
            'left' => ! $this->soldOut() && $scarcity->names($this->remaining) ? $this->remaining : null,
        ];
    }
}
