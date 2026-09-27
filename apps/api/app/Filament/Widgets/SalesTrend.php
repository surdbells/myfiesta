<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\PlatformMetrics;

/** Gross sales across the period, bucket by bucket. */
class SalesTrend extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.sales-trend';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $period = $this->period();
        $trend = app(PlatformMetrics::class)->trend($this->currency(), $period);

        return [
            'currency' => $this->currency(),
            'labels' => $trend['labels'],
            'gross' => $trend['gross'],
            'description' => 'What buyers paid, per '.$period->granularity().', '.$period->label().'. Refunds are counted separately.',
        ];
    }
}
