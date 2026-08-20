<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Splitting an integer amount into parts that add back up to it.
 *
 * Refunding three tickets out of an order needs each ticket's share of the
 * total, the tax, and the commission. Working each share out independently and
 * rounding it is how a full refund ends up a penny short of what the buyer
 * paid — and a penny that cannot be explained is worse than a large error that
 * can, because it goes unnoticed until reconciliation.
 *
 * So the split is done once, by largest remainder: give everyone their floor,
 * then hand the leftover units out to whoever was rounded down hardest. The
 * parts sum to the whole exactly, by construction rather than by luck.
 */
final class Allocation
{
    /**
     * @param  list<int>  $weights  relative shares, e.g. ticket prices
     * @return list<int>  one part per weight, summing exactly to $amount
     */
    public static function split(int $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Weights cannot be negative.');
            }
        }

        $totalWeight = array_sum($weights);

        // Everything free, or nothing to split. Spread evenly rather than
        // dividing by zero — an order fully covered by a code still has
        // tickets, and they still have to be refundable for nothing each.
        if ($totalWeight === 0) {
            return self::spreadEvenly($amount, count($weights));
        }

        $parts = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $exact = $amount * $weight;
            $parts[$index] = intdiv($exact, $totalWeight);
            $remainders[$index] = $exact % $totalWeight;
        }

        $leftover = $amount - array_sum($parts);

        // Largest remainder first; ties go to the earlier position so the same
        // input always produces the same split.
        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);

        for ($i = 0; $i < $leftover; $i++) {
            $parts[$order[$i % count($order)]]++;
        }

        ksort($parts);

        return array_values($parts);
    }

    /** @return list<int> */
    private static function spreadEvenly(int $amount, int $count): array
    {
        $base = intdiv($amount, $count);
        $parts = array_fill(0, $count, $base);

        for ($i = 0; $i < $amount - ($base * $count); $i++) {
            $parts[$i]++;
        }

        return $parts;
    }
}
