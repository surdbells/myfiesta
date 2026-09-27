<?php

namespace App\Filament\Resources\TaxRates\Tables;

use App\Filament\Support\Listing;
use App\Filament\Support\Outcome;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\StaffSupport\TaxRateChanges;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every tax rate, in force, scheduled and superseded.
 *
 * Rates are not money, so nothing here is per currency; what matters is the
 * jurisdiction and the dates, and the default view is what applies today.
 */
class TaxRatesTable
{
    public const COUNTRIES = ['CA' => 'Canada', 'NG' => 'Nigeria', 'US' => 'United States', 'GB' => 'United Kingdom'];

    public const STATES = ['in_force' => 'In force', 'scheduled' => 'Scheduled', 'superseded' => 'Superseded'];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'tax rates')
            ->searchPlaceholder('Name, country or province')
            ->columns([
                TextColumn::make('jurisdiction')
                    ->label('Jurisdiction')
                    ->state(fn (TaxRate $rate) => $rate->subdivision
                        ? "{$rate->country}-{$rate->subdivision}"
                        : $rate->country)
                    ->badge()
                    ->sortable(['country', 'subdivision'])
                    ->searchable(['country', 'subdivision']),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('rate_bps')
                    ->label('Rate')
                    ->formatStateUsing(fn (int $state) => self::percent($state))
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('inclusive')
                    ->label('In price')
                    ->boolean()
                    ->tooltip(fn (TaxRate $rate) => $rate->inclusive
                        ? 'Already inside the displayed price'
                        : 'Added at checkout'),

                TextColumn::make('default_currency')
                    ->label('Currency hint')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('effective_from')
                    ->label('From')
                    ->date('j M Y')
                    ->sortable(),

                TextColumn::make('effective_to')
                    ->label('Until')
                    ->date('j M Y')
                    ->placeholder('In force')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (TaxRate $rate) => self::STATES[self::stateOf($rate)])
                    ->color(fn (string $state) => match ($state) {
                        'In force' => 'success',
                        'Scheduled' => 'info',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('country')
            ->filters([
                SelectFilter::make('country')
                    ->options(self::COUNTRIES),

                SelectFilter::make('state')
                    ->label('Status')
                    ->options(self::STATES)
                    ->default('in_force')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'in_force' => $query
                            ->whereDate('effective_from', '<=', today())
                            ->where(fn (Builder $q) => $q
                                ->whereNull('effective_to')
                                ->orWhereDate('effective_to', '>', today())),
                        'scheduled' => $query->whereDate('effective_from', '>', today()),
                        'superseded' => $query
                            ->whereNotNull('effective_to')
                            ->whereDate('effective_to', '<=', today()),
                        default => $query,
                    }),

                TernaryFilter::make('inclusive')
                    ->label('Included in the price'),

                SelectFilter::make('subdivision')
                    ->label('Scope')
                    ->options(['country' => 'Country-wide', 'province' => 'One province or state'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'country' => $query->whereNull('subdivision'),
                        'province' => $query->whereNotNull('subdivision'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make(),

                // Superseding, rather than editing, is how a rate changes. The
                // old row keeps its dates so historic orders still explain
                // themselves; the new one takes over from the chosen date.
                Action::make('supersede')
                    ->label('Supersede')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn () => TaxRateChanges::allows(auth()->user()))
                    ->visible(fn (TaxRate $rate) => $rate->effective_to === null)
                    ->modalHeading(fn (TaxRate $rate) => 'Supersede '.$rate->name.' ('.self::percent($rate->rate_bps).')')
                    ->modalDescription(
                        'The current rate is closed on the date you choose and a new one takes '
                        .'over. Orders already placed keep pointing at the old rate.'
                    )
                    ->modalSubmitActionLabel('Supersede')
                    ->fillForm(fn (TaxRate $rate) => [
                        'rate_bps' => $rate->rate_bps / 100,
                        'effective_from' => max(today()->addDay(), $rate->effective_from->copy()->addDay())->toDateString(),
                    ])
                    ->schema(fn (TaxRate $rate) => [
                        TextInput::make('rate_bps')
                            ->label('New rate')
                            ->required()
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('0.01'),
                        DatePicker::make('effective_from')
                            ->label('New rate applies from')
                            ->required()
                            ->minDate(max(today(), $rate->effective_from->copy()->addDay()))
                            ->helperText('Today or later, and after the current rate started.'),
                    ])
                    ->action(fn (TaxRate $rate, array $data, Action $action) => Outcome::run(
                        $action,
                        'Rate superseded',
                        function (User $staff) use ($rate, $data) {
                            $new = app(TaxRateChanges::class)->supersede(
                                $rate,
                                $staff,
                                (int) round(((float) $data['rate_bps']) * 100),
                                Carbon::parse($data['effective_from']),
                            );

                            return self::percent($new->rate_bps).' from '.$new->effective_from->format('j M Y').'.';
                        },
                    )),
            ])
            // No bulk delete. A rate referenced by an order cannot be removed
            // without making that order unexplainable.
            ->toolbarActions([]);
    }

    public static function stateOf(TaxRate $rate): string
    {
        return match (true) {
            $rate->effective_to !== null && ! $rate->effective_to->isFuture() => 'superseded',
            $rate->effective_from->isFuture() => 'scheduled',
            default => 'in_force',
        };
    }

    private static function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2), '0'), '.').'%';
    }
}
