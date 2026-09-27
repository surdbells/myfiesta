<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\EventPerformance;
use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\PlatformMetrics;
use Carbon\CarbonImmutable;

/**
 * Of the people holding tickets to nights already held in the period, how
 * many came through the door.
 */
class CheckInRate extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.check-in-rate';

    protected static ?int $sort = 9;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $period = $this->period();
        $checkIns = app(PlatformMetrics::class)->checkIns($this->currency(), $period);

        return [
            'rate' => Format::percent($checkIns['overall']),
            'people' => number_format($checkIns['people']),
            'arrived' => number_format($checkIns['arrived']),
            'items' => array_map(fn (array $event) => [
                'label' => $event['title'],
                'value' => $event['arrived'],
                'capacity' => $event['people'],
                'hint' => CarbonImmutable::parse($event['starts_at'])->setTimezone($period->timezone)->format('j M Y'),
                'url' => EventPerformance::getUrl(['filters' => ['event' => $event['id']]]),
            ], $checkIns['events']),
        ];
    }
}
