<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\Metrics;
use App\Services\Analytics\PlatformMetrics;

/** Where the nights that sold were held: by city, and by country. */
class SalesByPlace extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.sales-by-place';

    protected static ?int $sort = 8;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $currency = $this->currency();
        $period = $this->period();
        $metrics = app(PlatformMetrics::class);

        $countries = $metrics->byCountry($currency, $period);
        $gross = array_sum(array_column($countries, 'gross'));

        return [
            'currency' => $currency,
            'cities' => array_map(fn (array $row) => [
                'label' => ($row['other'] ?? false) ? $row['city'] : trim($row['city'].($row['country'] !== '' ? ', '.$row['country'] : '')),
                'value' => $row['gross'],
                'hint' => number_format($row['orders']).' '.str('order')->plural($row['orders']),
                'other' => $row['other'] ?? false,
            ], $metrics->byCity($currency, $period)),
            'countries' => array_map(fn (array $row) => [
                'name' => Format::country($row['country']),
                'orders' => $row['orders'],
                'gross' => Format::money($row['gross'], $currency),
                'share' => Format::percent(Metrics::ratio($row['gross'], $gross)),
            ], $countries),
        ];
    }
}
