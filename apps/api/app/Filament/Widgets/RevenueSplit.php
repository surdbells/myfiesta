<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\PlatformMetrics;

/**
 * Where the money buyers paid went: to the organizer, to the platform, to a
 * tax authority, or back to the buyer.
 *
 * The ring is the period as a whole and adds up to its gross exactly; the
 * columns beside it are the same three kept shares bucket by bucket, dated
 * when the sale was made, with each order's refunds taken off the bucket it
 * was sold in — so the columns add up to the ring's kept parts.
 */
class RevenueSplit extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.revenue-split';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $currency = $this->currency();
        $period = $this->period();
        $metrics = app(PlatformMetrics::class);

        $split = $metrics->revenueSplit($currency, $period);
        $kept = $metrics->revenueSplitTrend($currency, $period);
        $fees = $metrics->overview($currency, $period)['current']['fees'];

        return [
            'currency' => $currency,
            'segments' => [
                ['label' => 'Organizers keep', 'value' => $split['organizer'], 'slot' => 1],
                ['label' => 'Platform service charge', 'value' => $split['platform'], 'slot' => 2],
                ['label' => 'Tax collected', 'value' => $split['tax'], 'slot' => 3],
                ['label' => 'Refunded to buyers', 'value' => $split['refunded'], 'slot' => 4],
            ],
            'labels' => $period->bucketLabels(),
            'series' => [
                ['name' => 'Organizers', 'values' => $kept['organizer'], 'slot' => 1],
                ['name' => 'Service charge', 'values' => $kept['platform'], 'slot' => 2],
                ['name' => 'Tax', 'values' => $kept['tax'], 'slot' => 3],
            ],
            'fees' => Format::money($fees, $currency),
            'granularity' => $period->granularity(),
        ];
    }
}
