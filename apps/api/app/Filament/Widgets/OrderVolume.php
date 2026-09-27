<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\PlatformMetrics;

/** Orders and the tickets on them, side by side, bucket by bucket. */
class OrderVolume extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.order-volume';

    protected static ?int $sort = 3;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $period = $this->period();
        $trend = app(PlatformMetrics::class)->trend($this->currency(), $period);

        return [
            'labels' => $trend['labels'],
            'series' => [
                ['name' => 'Orders', 'values' => $trend['orders'], 'slot' => 1],
                ['name' => 'Tickets', 'values' => $trend['tickets'], 'slot' => 2],
            ],
            'description' => 'Per '.$period->granularity().', online and at the door.',
        ];
    }
}
