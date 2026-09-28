<?php

namespace App\Services\Discovery;

use App\Models\TicketType;

/**
 * How a whole event is selling, and the cheapest way in that is left.
 *
 * `cheapest` is the tier the card's "From" price names. It is the same tier
 * the badge is about when the event is almost sold out because of one tier,
 * so the price and the badge on a card never describe two different tickets.
 */
final readonly class EventAvailability
{
    public function __construct(
        public string $state,
        /** Places left across every tier still selling; null when any has no limit. */
        public ?int $remaining,
        public ?TicketType $cheapest,
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
