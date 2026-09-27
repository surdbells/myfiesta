<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\PlatformMetrics;

/** Online checkout against sales made at the door. */
class SalesByChannel extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.sales-by-channel';

    protected static ?int $sort = 5;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $channels = app(PlatformMetrics::class)->byChannel($this->currency(), $this->period());

        return [
            'currency' => $this->currency(),
            'channels' => $channels,
            'segments' => [
                ['label' => 'Online', 'value' => $channels['online']['gross'], 'slot' => 1],
                ['label' => 'At the door', 'value' => $channels['door']['gross'], 'slot' => 2],
            ],
        ];
    }
}
