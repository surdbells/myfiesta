<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Sales tax for the launch markets.
 *
 * This table is the argument for keying tax on jurisdiction rather than
 * currency, made concrete: one currency, thirteen different rates. A CAD-keyed
 * lookup would be correct in Ontario and wrong everywhere else in Canada.
 * Nigeria is a single flat rate, which is exactly what makes the shortcut look
 * reasonable until you cross a border.
 *
 * Rates are seeded with an effective_from in the past so they resolve for
 * historic orders during the migration. Changing one later means superseding
 * it, never editing — an order has to stay explainable against the rate that
 * applied when it was placed.
 *
 * Confirm these against current CRA and FIRS guidance before launch. They are
 * correct as researched and are not a substitute for an accountant.
 */
class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $from = '2020-01-01';

        // Canada: HST provinces charge a single blended rate; the rest charge
        // 5% GST federally, with any provincial sales tax administered
        // separately by the province and out of scope here.
        $canada = [
            ['ON', 'HST', 1300],
            ['NB', 'HST', 1500],
            ['NL', 'HST', 1500],
            ['NS', 'HST', 1400],
            ['PE', 'HST', 1500],
            ['AB', 'GST', 500],
            ['BC', 'GST', 500],
            ['MB', 'GST', 500],
            ['SK', 'GST', 500],
            ['QC', 'GST', 500],
            ['NT', 'GST', 500],
            ['NU', 'GST', 500],
            ['YT', 'GST', 500],
        ];

        foreach ($canada as [$subdivision, $name, $bps]) {
            TaxRate::updateOrCreate(
                ['country' => 'CA', 'subdivision' => $subdivision, 'effective_to' => null],
                [
                    'default_currency' => 'CAD',
                    'name' => $name,
                    'rate_bps' => $bps,
                    // Canadian prices are advertised before tax.
                    'inclusive' => false,
                    'effective_from' => $from,
                ],
            );
        }

        // Fallback for a Canadian event whose province is unknown. Charging the
        // federal minimum is the conservative default; the alternative is
        // charging nothing.
        TaxRate::updateOrCreate(
            ['country' => 'CA', 'subdivision' => null, 'effective_to' => null],
            [
                'default_currency' => 'CAD',
                'name' => 'GST',
                'rate_bps' => 500,
                'inclusive' => false,
                'effective_from' => $from,
            ],
        );

        // Nigeria: flat VAT, and prices are quoted inclusive of it.
        TaxRate::updateOrCreate(
            ['country' => 'NG', 'subdivision' => null, 'effective_to' => null],
            [
                'default_currency' => 'NGN',
                'name' => 'VAT',
                'rate_bps' => 750,
                'inclusive' => true,
                'effective_from' => $from,
            ],
        );
    }
}
