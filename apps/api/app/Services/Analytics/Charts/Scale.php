<?php

namespace App\Services\Analytics\Charts;

/**
 * An axis a person can read: round ticks from zero to just above the data.
 *
 * Ticks land on 1, 2, 2.5 or 5 times a power of ten, so an axis reads 0 /
 * 250 / 500 / 750 rather than 0 / 237 / 474. Counts get whole-number steps —
 * half an order is not a tick anybody wants.
 */
final class Scale
{
    /**
     * @return array{max: float, step: float, ticks: list<float>}
     */
    public static function nice(float $max, int $count = 4, bool $integer = false): array
    {
        if ($max <= 0 || ! is_finite($max)) {
            $step = 1.0;

            return ['max' => $step * $count, 'step' => $step, 'ticks' => self::ticks($step * $count, $step)];
        }

        $raw = $max / max(1, $count);
        $magnitude = 10 ** floor(log10($raw));
        $normalised = $raw / $magnitude;

        $step = match (true) {
            $normalised <= 1 => 1,
            $normalised <= 2 => 2,
            $normalised <= 2.5 => 2.5,
            $normalised <= 5 => 5,
            default => 10,
        } * $magnitude;

        if ($integer) {
            $step = max(1.0, ceil($step));
        }

        $top = ceil($max / $step - 1e-9) * $step;

        return ['max' => $top, 'step' => $step, 'ticks' => self::ticks($top, $step)];
    }

    /** @return list<float> */
    private static function ticks(float $top, float $step): array
    {
        $ticks = [];

        for ($i = 0; $i * $step <= $top + $step / 1000; $i++) {
            $ticks[] = round($i * $step, 6);
        }

        return $ticks;
    }

    /**
     * Which of n labels to print on an axis so they do not collide: every
     * k-th, always including the first and the last.
     *
     * @return list<int>
     */
    public static function labelIndexes(int $n, int $max = 8): array
    {
        if ($n <= 0) {
            return [];
        }

        if ($n <= $max) {
            return range(0, $n - 1);
        }

        $every = (int) ceil(($n - 1) / ($max - 1));
        $indexes = [];

        for ($i = 0; $i < $n - 1; $i += $every) {
            $indexes[] = $i;
        }

        // The last label replaces a neighbour that would crowd it.
        if ($indexes !== [] && ($n - 1) - end($indexes) < $every / 2) {
            array_pop($indexes);
        }

        $indexes[] = $n - 1;

        return $indexes;
    }
}
