<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\Market;
use App\Services\Analytics\Period;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * A chart on a performance page, reading the page's market and period.
 *
 * Each one loads on its own after the page has drawn, so a slow query holds
 * up one card rather than the whole screen, and each re-renders when the
 * pickers change. None is discovered onto a dashboard by itself: a page names
 * the ones it shows.
 */
abstract class AnalyticsWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    /** Every member of staff may read the performance pages. */
    public static function canView(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    protected function currency(): string
    {
        return Market::normalize($this->pageFilters['currency'] ?? null);
    }

    protected function period(): Period
    {
        return Period::fromFilters($this->pageFilters, Market::timezone($this->currency()));
    }
}
