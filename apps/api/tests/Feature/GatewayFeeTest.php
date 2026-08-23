<?php

namespace Tests\Feature;

use App\Services\Payments\GatewayFee;
use App\Support\Money;
use Tests\TestCase;

/**
 * What the processor takes, in the same units as everything else.
 *
 * The live platform computed this as a generated column:
 *
 *     processing_fee = (payment_amount * 0.029) + 0.30
 *
 * with `payment_amount` held in cents. The percentage was right and the flat
 * fee was out by a hundred — thirty cents recorded as three tenths of one —
 * so every one of its paid orders reported more margin than it earned. These
 * assertions are in minor units for exactly that reason.
 */
class GatewayFeeTest extends TestCase
{
    public function test_stripe_takes_its_percentage_and_its_thirty_cents(): void
    {
        // $16.20 charged: 2.9% is 46.98c, plus 30c flat.
        $fee = GatewayFee::on(new Money(1620, 'CAD'), 'stripe');

        $this->assertSame(77, $fee->amount, '47 + 30, in cents.');
        $this->assertSame('CAD', $fee->currency);
    }

    public function test_the_flat_fee_is_not_three_tenths_of_a_cent(): void
    {
        // The regression, stated as a number. A charge small enough that the
        // percentage rounds to nothing leaves only the flat fee visible.
        $fee = GatewayFee::on(new Money(100, 'CAD'), 'stripe');

        $this->assertSame(33, $fee->amount);
        $this->assertNotSame(3, $fee->amount);
    }

    public function test_paystack_is_capped_so_a_large_ticket_is_not_overcharged(): void
    {
        // ₦5,000,000. 1.5% would be ₦75,000; the cap is ₦2,000.
        $fee = GatewayFee::on(new Money(500000000, 'NGN'), 'paystack');

        $this->assertSame(200000, $fee->amount);
    }

    public function test_paystack_below_the_cap_pays_the_percentage(): void
    {
        $fee = GatewayFee::on(new Money(100000, 'NGN'), 'paystack');

        $this->assertSame(1500, $fee->amount);
    }

    public function test_an_unconfigured_gateway_reports_nothing_rather_than_a_guess(): void
    {
        // Zero here means "no configured rate", and it is the honest answer:
        // a made-up fee would be reported as margin and reconciled against
        // nothing.
        $fee = GatewayFee::on(new Money(1000, 'CAD'), 'some_new_processor');

        $this->assertSame(0, $fee->amount);
    }
}
