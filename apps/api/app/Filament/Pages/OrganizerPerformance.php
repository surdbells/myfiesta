<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ImpersonateOrganizationAction;
use App\Filament\Pages\Concerns\ReadsAnalyticsFilters;
use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Support\Listing;
use App\Models\Organization;
use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\Metrics;
use App\Services\Analytics\OrganizerMetrics;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * How one organizer is doing, and how every organizer compares.
 *
 * Pick an organization for its report — sales over time, its nights ranked
 * and how full they are, how often money goes back, what it is owed against
 * what it has been paid, whether page views turn into orders and buyers come
 * back. Below, every organization with the same figures, to sort and filter.
 *
 * One market and period at a time, like the dashboard. Read-only: the one
 * thing a row offers beyond reading is opening the organizer's console, which
 * is its own audited action with its own role check.
 *
 * Payout request amounts and settlements are shown only to the staff the
 * payout request and settlement screens themselves admit; support sees that
 * a payout was asked for, not how much, and nothing of what was paid out.
 */
class OrganizerPerformance extends Page implements HasTable
{
    use HasFiltersForm, InteractsWithTable {
        InteractsWithTable::normalizeTableFilterValuesFromQueryString insteadof HasFiltersForm;
        HasFiltersForm::updatedFilters as protected persistUpdatedFilters;
    }
    use ReadsAnalyticsFilters;

    protected static ?string $title = 'Organizer performance';

    protected static ?string $slug = 'insights/organizers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?int $navigationSort = 10;

    private ?Organization $selected = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('organization')
                        ->label('Organization')
                        ->placeholder('Search for an organization')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Organization::query()
                            ->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                            ->orderBy('name')
                            ->limit(25)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Str::isUuid((string) $value)
                            ? Organization::withTrashed()->find($value)?->name
                            : null)
                        ->columnSpan(['md' => 2]),
                    ...$this->periodFields(),
                ])
                ->columns(['md' => 2, 'xl' => 6])
                ->columnSpanFull(),
        ]);
    }

    /** A different market or period is a different table: back to its first page. */
    public function updatedFilters(): void
    {
        $this->persistUpdatedFilters();
        $this->selected = null;
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.charts.styles'),
            EmbeddedSchema::make('filtersForm'),
            Text::make(fn (): string => $this->periodSummary())->color('gray'),
            View::make('filament.pages.organizer-report')
                ->viewData(fn (): array => $this->report())
                ->visible(fn (): bool => $this->selectedOrganization() !== null),
            Section::make('All organizers')
                ->description(fn (): string => 'Every organization\'s figures in '.$this->analyticsCurrency().' for '.$this->analyticsPeriod()->label().'. Owed is now, not at the end of the period.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function selectedOrganization(): ?Organization
    {
        $id = (string) ($this->filters['organization'] ?? '');

        if (! Str::isUuid($id)) {
            return null;
        }

        if ($this->selected?->getKey() !== $id) {
            $this->selected = Organization::withTrashed()->find($id);
        }

        return $this->selected;
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $organization = $this->selectedOrganization();

        if ($organization === null) {
            return [];
        }

        $currency = $this->analyticsCurrency();
        $period = $this->analyticsPeriod();
        $m = app(OrganizerMetrics::class)->for($organization->id, $currency, $period);

        $now = $m['current'];
        $before = $m['previous'];
        $money = fn (int $amount): string => Format::money($amount, $currency);
        $comparison = $period->comparisonLabel();
        $day = fn (string $at): string => CarbonImmutable::parse($at)->setTimezone($period->timezone)->format('j M Y');
        $seesRequests = PayoutRequestResource::canViewAny();
        $seesSettlements = SettlementResource::canViewAny();
        $pending = $m['payouts']['pending_request'];

        return [
            'organization' => $organization,
            'currency' => $currency,
            'comparison' => $comparison,
            'granularity' => $period->granularity(),
            'seesSettlements' => $seesSettlements,
            'tiles' => array_values(array_filter([
                ['label' => 'Gross sales', 'value' => $money($now['gross']), 'change' => Metrics::change($now['gross'], $before['gross']), 'trend' => $m['series']['gross'],
                    'hint' => $now['average_order'] !== null ? 'Average order '.$money($now['average_order']) : null],
                ['label' => 'Orders', 'value' => number_format($now['orders']), 'change' => Metrics::change($now['orders'], $before['orders']), 'trend' => $m['series']['orders'],
                    'hint' => number_format($now['door_orders']).' at the door'],
                ['label' => 'Tickets sold', 'value' => number_format($now['tickets']), 'change' => Metrics::change($now['tickets'], $before['tickets']), 'trend' => $m['series']['tickets']],
                ['label' => 'Refunded', 'value' => $money($now['refunded']), 'change' => Metrics::change($now['refunded'], $before['refunded']), 'upIsGood' => false, 'trend' => $m['series']['refunded'],
                    'hint' => Format::percent($now['refund_rate']).' of gross · '.number_format($now['refunds']).' '.str('refund')->plural($now['refunds'])],
                ['label' => 'Chargebacks opened', 'value' => number_format($m['disputes']['opened']),
                    'hint' => Format::percent($m['disputes']['rate']).' of orders · '.number_format($m['disputes']['open']).' open now'],
                // Below zero is money they owe us — an advance, or refunds
                // after a payout — and reads as that, not as a minus sign.
                $m['payouts']['owed'] < 0
                    ? ['label' => 'Owes myFiesta', 'value' => $money(-$m['payouts']['owed']),
                        'hint' => 'Recovered from their next sales before anything is paid out']
                    : ['label' => 'Owed now', 'value' => $money($m['payouts']['owed']),
                        'hint' => match (true) {
                            $pending === null => 'No payout requested',
                            $seesRequests => 'Payout of '.$money($pending).' requested',
                            default => 'A payout has been requested',
                        }],
                // What was paid out is for the staff who may open the settlements.
                $seesSettlements ? ['label' => 'Paid out in period', 'value' => $money($m['payouts']['paid']),
                    'hint' => number_format($m['payouts']['settlements']).' '.str('settlement')->plural($m['payouts']['settlements'])] : null,
                ['label' => 'Views to orders', 'value' => Format::percent($m['conversion']['rate']),
                    'hint' => $m['conversion']['views'] > 0
                        ? number_format($m['conversion']['online_orders']).' online '.str('order')->plural($m['conversion']['online_orders'])
                            .' from '.number_format($m['conversion']['views']).' page '.str('view')->plural($m['conversion']['views'])
                        : 'No page views recorded in this period'],
                // Two or more orders up to the end of the period, one of them in it.
                ['label' => 'Repeat buyers', 'value' => Format::percent($m['buyers']['rate']),
                    'hint' => number_format($m['buyers']['repeat']).' of '.number_format($m['buyers']['buyers']).' '.str('buyer')->plural($m['buyers']['buyers'])
                        .' ordered from them more than once'],
            ])),
            'labels' => $m['series']['labels'],
            'sales' => [
                ['name' => 'Gross sales', 'values' => $m['series']['gross'], 'slot' => 1],
                ['name' => 'Organizer keeps', 'values' => $m['series']['net'], 'slot' => 2],
            ],
            'payouts' => $seesSettlements ? [
                ['name' => 'Earned', 'values' => $m['payouts']['earned_series'], 'slot' => 1],
                ['name' => 'Paid out', 'values' => $m['payouts']['paid_series'], 'slot' => 3],
            ] : [],
            'events' => array_map(fn (array $event) => [
                'label' => $event['title'],
                'value' => $event['gross'],
                'hint' => $day($event['starts_at']).' · '.number_format($event['orders']).' '.str('order')->plural($event['orders']),
                'url' => EventPerformance::getUrl(['filters' => ['event' => $event['id']]]),
            ], $m['events']),
            'fill' => array_map(fn (array $event) => [
                'label' => $event['title'],
                'value' => $event['sold'],
                'capacity' => $event['capacity'],
                'hint' => $day($event['starts_at']).($event['comps'] > 0 ? ' · '.number_format($event['comps']).' comps not counted' : '').($event['capacity'] === null ? ' · no ceiling on at least one tier' : ''),
                'url' => EventPerformance::getUrl(['filters' => ['event' => $event['id']]]),
            ], $m['fill']),
            'split' => [
                ['label' => 'Organizer keeps', 'value' => $m['split']['organizer'], 'slot' => 1],
                ['label' => 'Platform service charge', 'value' => $m['split']['platform'], 'slot' => 2],
                ['label' => 'Tax collected', 'value' => $m['split']['tax'], 'slot' => 3],
                ['label' => 'Refunded to buyers', 'value' => $m['split']['refunded'], 'slot' => 4],
            ],
        ];
    }

    public function table(Table $table): Table
    {
        $currency = fn (): string => $this->analyticsCurrency();
        $money = fn (string $name, string $label): TextColumn => TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable()
            ->formatStateUsing(fn ($state): string => Format::money((int) $state, $currency()));

        return Listing::defaults($table, 'organizations')
            ->query(fn (): Builder => app(OrganizerMetrics::class)->table($this->analyticsCurrency(), $this->analyticsPeriod()))
            ->defaultSort('gross', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Organization $record): ?string => $record->trashed() ? 'Closed' : ($record->verified_at ? null : 'Not verified')),
                $money('gross', 'Gross sales'),
                TextColumn::make('orders_count')->label('Orders')->numeric()->alignEnd()->sortable(),
                TextColumn::make('tickets_sold')->label('Tickets')->numeric()->alignEnd()->sortable(),
                $money('refunded', 'Refunded'),
                TextColumn::make('refund_rate')
                    ->label('Refund rate')
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('refund_rate '.($direction === 'asc' ? 'asc' : 'desc').' nulls last'))
                    ->formatStateUsing(fn ($state): string => Format::percent($state === null ? null : (float) $state))
                    ->placeholder('—'),
                TextColumn::make('open_disputes')->label('Open chargebacks')->numeric()->alignEnd()->sortable()
                    ->color(fn ($state): ?string => (int) $state > 0 ? 'danger' : null),
                $money('owed', 'Owed now')
                    ->color(fn ($state): ?string => (int) $state < 0 ? 'danger' : null)
                    ->tooltip(fn ($state): ?string => (int) $state < 0 ? 'Below zero: they owe myFiesta this much' : null),
                $money('paid_out', 'Paid out')->visible(fn (): bool => SettlementResource::canViewAny()),
                TextColumn::make('events_in_period')->label('Nights')->numeric()->alignEnd()->sortable()
                    ->tooltip('Events held in the period, drafts left out'),
                TextColumn::make('last_sale_at')
                    ->label('Last sale')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('last_sale_at '.($direction === 'asc' ? 'asc' : 'desc').' nulls last'))
                    ->placeholder('Nothing in period'),
            ])
            ->filters([
                TernaryFilter::make('sold')
                    ->label('Sold in the period')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('orders_count', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('orders_count', 0),
                    ),
                TernaryFilter::make('verified')
                    ->label('Verified')
                    ->nullable()
                    ->attribute('verified_at'),
                TernaryFilter::make('disputes')
                    ->label('Open chargebacks')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('open_disputes', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('open_disputes', 0),
                    ),
                TernaryFilter::make('balance')
                    ->label('Balance')
                    ->placeholder('Any balance')
                    ->trueLabel('Owed money')
                    ->falseLabel('Overdrawn')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('owed', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('owed', '<', 0),
                    ),
                TernaryFilter::make('trashed')
                    ->label('Closed organizations')
                    ->placeholder('Open only')
                    ->trueLabel('Open and closed')
                    ->falseLabel('Closed only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->withTrashed(),
                        false: fn (Builder $query): Builder => $query->onlyTrashed(),
                        blank: fn (Builder $query): Builder => $query->withoutTrashed(),
                    ),
            ])
            ->recordActions([
                Action::make('report')
                    ->label('Report')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->color('gray')
                    ->url(fn (Organization $record): string => static::getUrl(['filters' => [...($this->filters ?? []), 'organization' => $record->getKey()]])),
                ImpersonateOrganizationAction::make(),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('No organizations match')
            ->emptyStateDescription('Clear the search or filters, or pick another period.');
    }
}
