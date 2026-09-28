<?php

namespace App\Services\Discovery;

/**
 * When a ticket reads as nearly gone, as the admin has it set.
 *
 * Whole numbers throughout, and integer arithmetic: the same rule is written
 * once more as SQL for the filters (Availability::stateSql), and a percentage
 * worked out in floating point on one side and exactly on the other is how
 * the badge and the filter would come to disagree about the one tier sitting
 * on the line.
 */
final readonly class Scarcity
{
    public function __construct(
        /** A share of capacity, 0–100. */
        public int $percent,
        /** The fewest places that still read as nearly gone. */
        public int $floor,
        /** The most places ever named exactly. Zero names none. */
        public int $exactUnder,
    ) {}

    /** @param  array{percent: int, floor: int, exact_under: int}  $settings */
    public static function from(array $settings): self
    {
        return new self(
            max(0, min(100, $settings['percent'])),
            max(0, $settings['floor']),
            max(0, $settings['exact_under']),
        );
    }

    /**
     * How few places left counts as nearly gone, out of this many: the larger
     * of the floor and the share, rounded up — 5 of 40, 10 of 100, 50 of 500.
     */
    public function near(int $capacity): int
    {
        return max($this->floor, intdiv(max(0, $capacity) * $this->percent + 99, 100));
    }

    /** Whether this many left may be said as a number. */
    public function names(?int $left): bool
    {
        return $left !== null && $left > 0 && $left <= $this->exactUnder;
    }
}
