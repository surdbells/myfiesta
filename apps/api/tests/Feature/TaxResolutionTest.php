<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use App\Support\Money;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tax resolution, which is the reason rates are keyed on jurisdiction.
 *
 * The tempting shortcut is to key them on currency. These tests are what that
 * shortcut would fail: one currency, several different correct answers.
 */
class TaxResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);
    }

    public function test_one_currency_resolves_to_different_rates_by_province(): void
    {
        $ontario = TaxRate::resolve('CA', 'ON');
        $alberta = TaxRate::resolve('CA', 'AB');
        $novaScotia = TaxRate::resolve('CA', 'NS');

        $this->assertSame(1300, $ontario->rate_bps, 'Ontario should be 13% HST.');
        $this->assertSame(500, $alberta->rate_bps, 'Alberta should be 5% GST.');
        $this->assertSame(1400, $novaScotia->rate_bps, 'Nova Scotia should be 14% HST.');

        // The point, stated plainly: three CAD events, three correct rates.
        // A currency-keyed table has one row here and is wrong twice.
        $this->assertNotSame($ontario->rate_bps, $alberta->rate_bps);
    }

    public function test_an_unknown_province_falls_back_to_the_country_rate(): void
    {
        $rate = TaxRate::resolve('CA', 'ZZ');

        $this->assertNotNull($rate, 'A Canadian event must resolve to something.');
        $this->assertNull($rate->subdivision, 'Expected the country-wide fallback.');
        $this->assertSame(500, $rate->rate_bps);
    }

    public function test_a_country_specific_row_beats_the_fallback(): void
    {
        $rate = TaxRate::resolve('CA', 'ON');

        $this->assertSame('ON', $rate->subdivision, 'The province row must win over the fallback.');
    }

    public function test_nigeria_is_flat_and_inclusive(): void
    {
        $rate = TaxRate::resolve('NG', null);

        $this->assertSame(750, $rate->rate_bps);
        $this->assertTrue($rate->inclusive, 'Nigerian prices are quoted with VAT included.');
    }

    public function test_a_country_with_no_rate_resolves_to_nothing(): void
    {
        // Silence, not a guess. Charging an invented rate is worse than
        // refusing to publish an event we cannot tax correctly.
        $this->assertNull(TaxRate::resolve('JP', null));
    }

    public function test_a_superseded_rate_still_answers_for_the_date_it_applied(): void
    {
        $current = TaxRate::resolve('CA', 'ON');
        $current->update(['effective_to' => '2026-01-01']);

        TaxRate::create([
            'country' => 'CA',
            'subdivision' => 'ON',
            'default_currency' => 'CAD',
            'name' => 'HST',
            'rate_bps' => 1400,
            'inclusive' => false,
            'effective_from' => '2026-01-01',
        ]);

        // An order from last year must still price against last year's rate,
        // which is why rates are superseded rather than edited.
        $this->assertSame(1300, TaxRate::resolve('CA', 'ON', '2025-06-01')->rate_bps);
        $this->assertSame(1400, TaxRate::resolve('CA', 'ON', '2026-06-01')->rate_bps);
    }

    public function test_tax_is_calculated_on_the_discounted_amount(): void
    {
        // Order of operations: discount first, then tax on what remains, then
        // commission on the net. Taxing the pre-discount price overcharges the
        // buyer on money nobody received.
        $subtotal = Money::of(10000, 'CAD');
        $discount = Money::of(2000, 'CAD');
        $taxable = $subtotal->minus($discount);

        $tax = $taxable->percentage(TaxRate::resolve('CA', 'ON')->rate_bps);

        $this->assertSame(1040, $tax->amount, '13% of 80.00 is 10.40, not 13.00.');
        $this->assertSame(9040, $subtotal->minus($discount)->plus($tax)->amount);
    }
}
