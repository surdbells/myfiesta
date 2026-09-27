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
 * The one list of launch rates. A deploy installs them through a migration
 * (…_the_launch_tax_rates), which runs this; a developer's database gets them
 * from db:seed. Same rows either way.
 *
 * Rates are seeded with an effective_from in the past so they resolve for
 * historic orders during the migration. Changing one later means superseding
 * it, never editing — an order has to stay explainable against the rate that
 * applied when it was placed. So this only ever adds a rate for a place that
 * has none: it never touches one that exists, whether this made it, an
 * earlier version of this made it, or staff superseded it since.
 *
 * Quebec is 5% GST here, and only that. QST is collected beside it when the
 * platform is registered for it, and is a platform setting rather than a row
 * (PlatformSettings), because its rate has three decimals.
 *
 * Confirm these against current CRA and FIRS guidance before launch. They are
 * correct as researched and are not a substitute for an accountant.
 */
class TaxRateSeeder extends Seeder
{
    public const FROM = '2020-01-01';

    /**
     * Country, province, name, basis points, inside the price, currency hint.
     *
     * Canada: HST provinces charge a single blended rate; the rest charge 5%
     * GST federally, with any provincial sales tax administered separately by
     * the province and out of scope here. The row without a province is the
     * fallback for a Canadian event whose province is unknown: charging the
     * federal minimum is the conservative default, the alternative being
     * nothing. Canadian prices are advertised before tax.
     *
     * Nigeria: flat VAT, and prices are quoted inclusive of it.
     *
     * @var list<array{0: string, 1: ?string, 2: string, 3: int, 4: bool, 5: string}>
     */
    public const RATES = [
        ['CA', 'ON', 'HST', 1300, false, 'CAD'],
        ['CA', 'NB', 'HST', 1500, false, 'CAD'],
        ['CA', 'NL', 'HST', 1500, false, 'CAD'],
        ['CA', 'NS', 'HST', 1400, false, 'CAD'],
        ['CA', 'PE', 'HST', 1500, false, 'CAD'],
        ['CA', 'AB', 'GST', 500, false, 'CAD'],
        ['CA', 'BC', 'GST', 500, false, 'CAD'],
        ['CA', 'MB', 'GST', 500, false, 'CAD'],
        ['CA', 'SK', 'GST', 500, false, 'CAD'],
        ['CA', 'QC', 'GST', 500, false, 'CAD'],
        ['CA', 'NT', 'GST', 500, false, 'CAD'],
        ['CA', 'NU', 'GST', 500, false, 'CAD'],
        ['CA', 'YT', 'GST', 500, false, 'CAD'],
        ['CA', null, 'GST', 500, false, 'CAD'],
        ['NG', null, 'VAT', 750, true, 'NGN'],
    ];

    public function run(): void
    {
        $this->install();
    }

    /**
     * Add each launch rate whose place has no rate at all. Returns how many.
     *
     * "No rate at all" rather than "no rate in force": a place whose rate was
     * superseded has a history, and adding a launch rate beside it would put
     * a second row in force next to the one staff chose.
     */
    public function install(): int
    {
        $added = 0;

        foreach (self::RATES as [$country, $subdivision, $name, $bps, $inclusive, $currency]) {
            $exists = TaxRate::query()
                ->where('country', $country)
                ->where(fn ($q) => $subdivision === null
                    ? $q->whereNull('subdivision')
                    : $q->where('subdivision', $subdivision))
                ->exists();

            if ($exists) {
                continue;
            }

            TaxRate::create([
                'country' => $country,
                'subdivision' => $subdivision,
                'default_currency' => $currency,
                'name' => $name,
                'rate_bps' => $bps,
                'inclusive' => $inclusive,
                'effective_from' => self::FROM,
            ]);

            $added++;
        }

        return $added;
    }
}
