<?php

namespace Tests\Unit;

use App\Support\Allocation;
use PHPUnit\Framework\TestCase;

/**
 * Splitting money without losing any.
 *
 * The one property that matters is that the parts add back up to the whole.
 * Everything else here exists to stop a future simplification from quietly
 * trading it away.
 */
class AllocationTest extends TestCase
{
    public function test_an_even_split_is_even(): void
    {
        $this->assertSame([25, 25, 25, 25], Allocation::split(100, [1, 1, 1, 1]));
    }

    public function test_the_leftover_goes_somewhere_rather_than_nowhere(): void
    {
        // 100 across three is 33.33 each. Rounding each down loses a unit, and
        // a lost unit is the kind of error that surfaces at reconciliation with
        // nothing to attribute it to.
        $parts = Allocation::split(100, [1, 1, 1]);

        $this->assertSame(100, array_sum($parts));
        $this->assertSame([34, 33, 33], $parts);
    }

    public function test_weights_are_respected(): void
    {
        // A table of five and a single ticket do not get the same share.
        $this->assertSame([5000, 1000], Allocation::split(6000, [5000, 1000]));
    }

    public function test_the_split_is_the_same_every_time(): void
    {
        // Partial refunds split the same order more than once. If the leftover
        // moved between calls, two refunds could claim the same spare unit.
        $first = Allocation::split(9999, [3, 5, 7, 11]);
        $second = Allocation::split(9999, [3, 5, 7, 11]);

        $this->assertSame($first, $second);
    }

    public function test_the_parts_always_sum_to_the_whole(): void
    {
        // Awkward on purpose: primes, a zero, and totals that divide cleanly
        // by nothing in the list.
        foreach ([1, 7, 99, 100, 1301, 999_999] as $total) {
            foreach ([[1], [1, 2], [3, 5, 7], [11, 0, 13, 17], [1, 1, 1, 1, 1, 1, 1]] as $weights) {
                $parts = Allocation::split($total, $weights);

                $this->assertSame($total, array_sum($parts), "total {$total}");
                $this->assertCount(count($weights), $parts);
            }
        }
    }

    public function test_nothing_to_split_is_not_an_error(): void
    {
        $this->assertSame([0, 0], Allocation::split(0, [5, 3]));
        $this->assertSame([], Allocation::split(100, []));
    }

    public function test_a_free_order_still_splits(): void
    {
        // Every ticket covered by a full-value code. There is no weight to go
        // on, so the split is even rather than a division by zero.
        $this->assertSame([1, 1, 1], Allocation::split(3, [0, 0, 0]));
    }

    public function test_a_zero_weight_gets_nothing(): void
    {
        // A comp issued against a paid order refunds nothing, which is right.
        $this->assertSame([100, 0], Allocation::split(100, [1, 0]));
    }
}
