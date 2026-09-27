<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\OrganizerPerformance;
use App\Services\Analytics\PlatformMetrics;

/** The ten organizers who sold the most in the period, each a link to their report. */
class TopOrganizers extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.top-organizers';

    protected static ?int $sort = 6;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $currency = $this->currency();
        $filters = $this->pageFilters ?? [];

        return [
            'currency' => $currency,
            'items' => array_map(fn (array $row) => [
                'label' => $row['name'],
                'value' => $row['gross'],
                'hint' => number_format($row['orders']).' '.str('order')->plural($row['orders']),
                'url' => OrganizerPerformance::getUrl(['filters' => [...$filters, 'organization' => $row['id']]]),
            ], app(PlatformMetrics::class)->topOrganizers($currency, $this->period())),
        ];
    }
}
