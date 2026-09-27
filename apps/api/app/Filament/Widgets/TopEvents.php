<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\EventPerformance;
use App\Services\Analytics\PlatformMetrics;
use Carbon\CarbonImmutable;

/** The ten nights that sold the most in the period, each a link to its report. */
class TopEvents extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.top-events';

    protected static ?int $sort = 7;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $currency = $this->currency();
        $period = $this->period();

        return [
            'currency' => $currency,
            'items' => array_map(fn (array $row) => [
                'label' => $row['title'],
                'value' => $row['gross'],
                'hint' => $row['organization'].' · '.CarbonImmutable::parse($row['starts_at'])->setTimezone($period->timezone)->format('j M Y')
                    .' · '.number_format($row['orders']).' '.str('order')->plural($row['orders']),
                'url' => EventPerformance::getUrl(['filters' => ['event' => $row['id']]]),
            ], app(PlatformMetrics::class)->topEvents($currency, $period)),
        ];
    }
}
