<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ReadsAnalyticsFilters;
use App\Filament\Widgets\CheckInRate;
use App\Filament\Widgets\OrderVolume;
use App\Filament\Widgets\PlatformKpis;
use App\Filament\Widgets\RevenueSplit;
use App\Filament\Widgets\SalesByChannel;
use App\Filament\Widgets\SalesByPlace;
use App\Filament\Widgets\SalesTrend;
use App\Filament\Widgets\TopEvents;
use App\Filament\Widgets\TopOrganizers;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * How the platform is doing, one market at a time.
 *
 * Replaces Filament's welcome screen. The pickers at the top choose a market —
 * dollars or naira, never both at once — and a period in that market's own
 * clock; every card below reads them, and each loads on its own so a slow
 * query holds up one card rather than the page.
 *
 * Every member of staff may read it. Nothing on it changes anything.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;
    use ReadsAnalyticsFilters;

    protected static ?string $title = 'Platform performance';

    protected static ?string $navigationLabel = 'Dashboard';

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema($this->periodFields())
                ->columns(['md' => 2, 'xl' => 4])
                ->columnSpanFull(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            PlatformKpis::class,
            SalesTrend::class,
            RevenueSplit::class,
            OrderVolume::class,
            SalesByChannel::class,
            TopOrganizers::class,
            TopEvents::class,
            SalesByPlace::class,
            CheckInRate::class,
        ];
    }

    /**
     * Two columns only on a window wide enough for a chart in each beside
     * the sidebar: at xl a half-width card is narrower than a chart's
     * smallest width, and it scrolls sideways with its figures cut off.
     */
    public function getColumns(): int|array
    {
        return ['md' => 1, '2xl' => 2];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.charts.styles'),
            $this->getFiltersFormContentComponent(),
            Text::make(fn (): string => $this->periodSummary())->color('gray'),
            $this->getWidgetsContentComponent(),
        ]);
    }
}
