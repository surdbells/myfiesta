<?php

namespace App\Filament\Pages;

use App\Enums\EventStatus;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\Listing;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\EventMetrics;
use App\Services\Analytics\Metrics;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * How one night sold and ran, and how every night compares.
 *
 * Pick an event for its report: how fast it sold towards the night, which
 * tiers and codes sold it, where the money went, how the door filled over the
 * evening fifteen minutes at a time, door against online, and what was
 * refunded. Below, every event with the same measures, to sort and filter.
 *
 * Over each event's whole life rather than a period, and in the event's own
 * currency — an event sells in exactly one, so a row never mixes two.
 */
class EventPerformance extends Page implements HasTable
{
    use HasFiltersForm, InteractsWithTable {
        InteractsWithTable::normalizeTableFilterValuesFromQueryString insteadof HasFiltersForm;
        HasFiltersForm::updatedFilters as protected persistUpdatedFilters;
    }

    protected static ?string $title = 'Event performance';

    protected static ?string $slug = 'insights/events';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?int $navigationSort = 20;

    private ?Event $selected = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('event')
                        ->label('Event')
                        ->placeholder('Search by event or organizer')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::searchEvents($search))
                        ->getOptionLabelUsing(fn ($value): ?string => Str::isUuid((string) $value)
                            ? self::optionLabel(Event::withTrashed()->with('organization:id,name')->find($value))
                            : null)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    private static function searchEvents(string $search): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        return Event::query()
            ->with('organization:id,name')
            ->where(fn (Builder $query) => $query
                ->where('title', 'ilike', $like)
                ->orWhereIn('organization_id', Organization::query()->where('name', 'ilike', $like)->select('id')))
            ->orderByDesc('starts_at')
            ->limit(25)
            ->get(['id', 'title', 'organization_id', 'starts_at', 'timezone', 'currency'])
            ->mapWithKeys(fn (Event $event): array => [$event->id => self::optionLabel($event)])
            ->all();
    }

    private static function optionLabel(?Event $event): ?string
    {
        if ($event === null) {
            return null;
        }

        return $event->title.' — '.($event->organization?->name ?? 'Unknown organizer')
            .' · '.$event->starts_at?->setTimezone($event->timezone ?: 'UTC')->format('j M Y')
            .' · '.$event->currency;
    }

    public function updatedFilters(): void
    {
        $this->persistUpdatedFilters();
        $this->selected = null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.charts.styles'),
            EmbeddedSchema::make('filtersForm'),
            View::make('filament.pages.event-report')
                ->viewData(fn (): array => $this->report())
                ->visible(fn (): bool => $this->selectedEvent() !== null),
            Section::make('All events')
                ->description('Each over its whole life, in its own currency. Money is never added across events in different currencies, and sorting by money ranks each market on its own.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function selectedEvent(): ?Event
    {
        $id = (string) ($this->filters['event'] ?? '');

        if (! Str::isUuid($id)) {
            return null;
        }

        if ($this->selected?->getKey() !== $id) {
            $this->selected = Event::withTrashed()->with('organization:id,name')->find($id);
        }

        return $this->selected;
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $event = $this->selectedEvent();

        if ($event === null) {
            return [];
        }

        $m = app(EventMetrics::class)->for($event);
        $currency = $event->currency;
        $money = fn (int $amount): string => Format::money($amount, $currency);
        $t = $m['totals'];
        $a = $m['attendance'];
        $zone = $event->timezone ?: 'UTC';

        return [
            'event' => $event,
            'currency' => $currency,
            'starts' => $event->starts_at?->setTimezone($zone)->format('D j M Y, H:i').' ('.$zone.')',
            'eventUrl' => self::eventUrl($event),
            'tiles' => [
                ['label' => 'Gross sales', 'value' => $money($t['gross']),
                    'hint' => $t['average_order'] !== null ? 'Average order '.$money($t['average_order']) : null],
                // Sold counts every ticket ever paid for, refunded ones too; how
                // full the room is counts only the paid tickets still valid, and
                // the hint says which number it divides.
                ['label' => 'Tickets sold', 'value' => number_format($t['tickets']),
                    'hint' => number_format($a['sold']).' still valid'
                        .($a['capacity'] !== null
                            ? ', '.Format::percent(Metrics::ratio($a['sold'], $a['capacity'])).' of '.number_format($a['capacity']).' '.str('place')->plural($a['capacity'])
                            : ' · at least one tier has no ceiling')
                        .($a['comps'] > 0 ? ' · '.number_format($a['comps']).' '.str('comp')->plural($a['comps']) : '')],
                ['label' => 'Orders', 'value' => number_format($t['orders']),
                    'hint' => number_format($t['door_orders']).' at the door · '.number_format($t['online_orders']).' online'],
                ['label' => 'Refunded', 'value' => $money($t['refunded']),
                    'hint' => Format::percent($t['refund_rate']).' of gross · '.number_format($t['refunds']).' '.str('refund')->plural($t['refunds'])],
                ['label' => 'Checked in', 'value' => Format::percent($a['rate']),
                    'hint' => number_format($a['arrived']).' of '.number_format($a['people']).' '.($a['people'] === 1 ? 'person' : 'people')
                        .' · '.number_format($m['check_ins']['turned_away']).' '.str('scan')->plural($m['check_ins']['turned_away']).' turned away'],
                ['label' => 'Page views', 'value' => number_format($m['views']),
                    'hint' => $m['views'] > 0
                        ? Format::percent(Metrics::ratio($t['online_orders'], $m['views'])).' became online orders'
                        : 'No views recorded'],
                ['label' => 'Orders with a code', 'value' => number_format($m['codes']['orders']),
                    'hint' => Format::percent(Metrics::ratio($m['codes']['orders'], $t['orders'])).' of orders · '.$money($t['discount']).' discounted'],
                ['label' => 'Platform service charge', 'value' => $money($t['service']),
                    'hint' => $money($t['fees']).' in processor fees'.($t['fees_pending'] > 0 ? ' · '.$t['fees_pending'].' not settled yet' : '')],
            ],
            'pace' => [
                'labels' => array_map(fn (array $p): string => $p['days_before'] === 0 ? 'Night' : $p['days_before'].'d', $m['pace']),
                'series' => [['name' => 'Tickets sold so far', 'values' => array_column($m['pace'], 'tickets'), 'slot' => 1]],
            ],
            'types' => array_map(fn (array $type) => [
                'label' => $type['name'].($type['removed'] ? ' (removed)' : ''),
                'value' => $type['tickets'],
                'capacity' => $type['capacity'],
                'hint' => $money($type['revenue']).' in ticket revenue',
            ], $m['ticket_types']),
            'typeRevenue' => array_map(fn (array $type) => [
                'label' => $type['name'].($type['removed'] ? ' (removed)' : ''),
                'value' => $type['revenue'],
                'hint' => number_format($type['tickets']).' '.str('ticket')->plural($type['tickets']),
            ], $m['ticket_types']),
            'split' => [
                ['label' => 'Organizer keeps', 'value' => $m['split']['organizer'], 'slot' => 1],
                ['label' => 'Platform service charge', 'value' => $m['split']['platform'], 'slot' => 2],
                ['label' => 'Tax collected', 'value' => $m['split']['tax'], 'slot' => 3],
                ['label' => 'Refunded to buyers', 'value' => $m['split']['refunded'], 'slot' => 4],
            ],
            'codes' => array_map(fn (array $code) => [
                'label' => $code['code'].($code['removed'] ? ' (removed)' : ''),
                'value' => $code['orders'],
                'hint' => trim(($code['label'] ? $code['label'].' · ' : '').$money($code['discount']).' off · '.$money($code['gross']).' gross'),
            ], $m['codes']['codes']),
            'checkIns' => $m['check_ins'],
            'channels' => $m['channels'],
            'channelSegments' => [
                ['label' => 'Online', 'value' => $m['channels']['online']['gross'], 'slot' => 1],
                ['label' => 'At the door', 'value' => $m['channels']['door']['gross'], 'slot' => 2],
            ],
            'refunds' => array_map(fn (array $refund) => [
                'reference' => $refund['reference'],
                'amount' => $money($refund['amount']),
                'status' => ucfirst($refund['status']),
                'reason' => $refund['reason'] ? Str::headline($refund['reason']) : '—',
                'when' => CarbonImmutable::parse($refund['created_at'])->setTimezone($zone)->format('j M Y, H:i'),
            ], $m['refunds']),
        ];
    }

    private static function eventUrl(Event $event): ?string
    {
        try {
            return EventResource::canView($event) ? EventResource::getUrl('view', ['record' => $event]) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function table(Table $table): Table
    {
        // Rows are in their own event's currency, so a money sort ranks each
        // market on its own — dollars, then naira — and never compares cents
        // with kobo. With the market filter set there is only one to rank.
        $money = fn (string $name, string $label): TextColumn => TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                ->orderBy('events.currency')
                ->orderBy('events.'.$name, $direction === 'asc' ? 'asc' : 'desc'))
            ->formatStateUsing(fn ($state, Event $record): string => Format::money((int) $state, (string) $record->currency));

        $rate = fn (string $name, string $label): TextColumn => TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw($name.' '.($direction === 'asc' ? 'asc' : 'desc').' nulls last'))
            ->formatStateUsing(fn ($state): string => Format::percent($state === null ? null : (float) $state))
            ->placeholder('—');

        return Listing::defaults($table, 'events')
            ->query(fn (): Builder => app(EventMetrics::class)->table()->with('organization:id,name'))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Event')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('events.title', 'ilike', '%'.$search.'%')
                        ->orWhere('events.city', 'ilike', '%'.$search.'%')
                        ->orWhereIn('events.organization_id', Organization::query()->where('name', 'ilike', '%'.$search.'%')->select('id'))))
                    ->sortable()
                    ->wrap()
                    ->description(fn (Event $record): string => ($record->organization?->name ?? 'Unknown organizer').' · '.$record->city),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->sortable()
                    ->formatStateUsing(fn ($state, Event $record): string => $record->starts_at?->setTimezone($record->timezone ?: 'UTC')->format('j M Y, H:i') ?? '—')
                    ->description(fn (Event $record): string => $record->timezone ?: 'UTC'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EventStatus::tryFrom($state)?->label() ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'in_review' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('currency')->label('Market'),
                $money('gross', 'Gross sales'),
                TextColumn::make('orders_count')->label('Orders')->numeric()->alignEnd()->sortable(),
                TextColumn::make('tickets_sold')->label('Tickets')->numeric()->alignEnd()->sortable(),
                TextColumn::make('capacity')
                    ->label('Capacity')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('capacity '.($direction === 'asc' ? 'asc' : 'desc').' nulls last'))
                    ->placeholder('No ceiling'),
                $rate('fill_rate', 'Filled')->tooltip('Paid tickets still valid, against capacity — refunded and voided ones freed their place'),
                $money('refunded', 'Refunded'),
                $rate('refund_rate', 'Refund rate'),
                $rate('check_in_rate', 'Checked in'),
                TextColumn::make('door_orders')->label('Door orders')->numeric()->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('views')->label('Views')->numeric()->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                $rate('conversion', 'Views to orders')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('currency')->label('Market')->options(Listing::CURRENCIES),
                SelectFilter::make('status')->options([
                    'published' => 'Published',
                    'in_review' => 'In review',
                    'draft' => 'Draft',
                    'cancelled' => 'Cancelled',
                ]),
                SelectFilter::make('kind')->options([
                    'ticketed' => 'Ticketed',
                    'invitation' => 'Invitation only',
                ]),
                SelectFilter::make('organization_id')
                    ->label('Organizer')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Organization::query()
                        ->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                        ->orderBy('name')
                        ->limit(25)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Str::isUuid((string) $value) ? Organization::withTrashed()->find($value)?->name : null),
                TernaryFilter::make('when')
                    ->label('When')
                    ->placeholder('Past and upcoming')
                    ->trueLabel('Upcoming')
                    ->falseLabel('Already held')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereRaw('coalesce(events.ends_at, events.starts_at) >= now()'),
                        false: fn (Builder $query): Builder => $query->whereRaw('coalesce(events.ends_at, events.starts_at) < now()'),
                    ),
                TernaryFilter::make('sold')
                    ->label('Has sales')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('orders_count', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('orders_count', 0),
                    ),
                Listing::dateRange('starts', 'starts_at', 'Starts'),
                TrashedFilter::make()->label('Removed events'),
            ])
            ->recordActions([
                Action::make('report')
                    ->label('Report')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->color('gray')
                    ->url(fn (Event $record): string => static::getUrl(['filters' => ['event' => $record->getKey()]])),
                Action::make('open')
                    ->label('Event')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (Event $record): ?string => self::eventUrl($record))
                    ->visible(fn (Event $record): bool => self::eventUrl($record) !== null),
            ])
            ->recordUrl(null);
    }
}
