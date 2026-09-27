<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * Money as a person reads it in an email or on an admin screen.
 *
 * Pinned to what packages/shared/src/money.ts writes, because the email and
 * the screen it links to are read side by side: "CA$113.00" in the inbox and
 * "$113.00" on the ticket read as two different amounts.
 */
class MoneyTest extends TestCase
{
    public function test_dollars_are_written_as_canadians_write_them(): void
    {
        $this->assertSame('$1,250.00', Money::of(125000, 'CAD')->format());
        $this->assertSame('$0.40', Money::of(40, 'CAD')->format());
        $this->assertSame('$0.00', Money::zero('CAD')->format());
    }

    public function test_dollars_always_show_their_cents(): void
    {
        $this->assertSame('$25.00', Money::of(2500, 'CAD')->format());
    }

    public function test_naira_drop_the_kobo_when_there_are_none(): void
    {
        $this->assertSame('₦5,000', Money::of(500000, 'NGN')->format());
        $this->assertSame('₦45,000', Money::of(4500000, 'NGN')->format());
        $this->assertSame('₦0', Money::zero('NGN')->format());
    }

    public function test_naira_with_kobo_keep_both_digits(): void
    {
        // VAT on an odd price, or a percentage discount. Rounding it away
        // would leave a receipt that does not add up.
        $this->assertSame('₦206.25', Money::of(20625, 'NGN')->format());
        $this->assertSame('₦1,000.05', Money::of(100005, 'NGN')->format());
    }

    public function test_a_negative_amount_carries_its_sign_in_front(): void
    {
        $this->assertSame('-$113.00', Money::of(-11300, 'CAD')->format());
        $this->assertSame('-₦5,000', Money::of(-500000, 'NGN')->format());
    }

    public function test_a_currency_nothing_is_sold_in_is_written_as_its_code(): void
    {
        // Never "$", so it cannot be read as Canadian dollars.
        $this->assertSame('USD 12.50', Money::of(1250, 'USD')->format());
        $this->assertSame('USD ', Money::symbol('usd'));
    }

    public function test_the_symbol_alone_is_the_one_in_the_amount(): void
    {
        $this->assertSame('$', Money::symbol('CAD'));
        $this->assertSame('₦', Money::symbol('NGN'));
    }
}
