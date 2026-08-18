<?php

namespace App\Filament\Resources\TaxRates\Tables;

use App\Models\TaxRate;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TaxRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->searchable(),

                TextColumn::make('rate_bps')
                    ->label('Rate')
                    ->formatStateUsing(fn (int $state) => rtrim(rtrim(number_format($state / 100, 2), '0'), '.').'%')
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('inclusive')
                    ->label('In price')
                    ->boolean()
                    ->tooltip(fn (TaxRate $rate) => $rate->inclusive
                        ? 'Already inside the displayed price'
                        : 'Added at checkout'),

                TextColumn::make('effective_from')
                    ->label('From')
                    ->date()
                    ->sortable(),

                TextColumn::make('effective_to')
                    ->label('Until')
                    ->date()
                    ->placeholder('In force')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (TaxRate $rate) => match (true) {
                        $rate->effective_to !== null && $rate->effective_to->isPast() => 'Superseded',
                        $rate->effective_from->isFuture() => 'Scheduled',
                        default => 'In force',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'In force' => 'success',
                        'Scheduled' => 'info',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('country')
            ->filters([
                SelectFilter::make('country')
                    ->options(['CA' => 'Canada', 'NG' => 'Nigeria', 'US' => 'United States', 'GB' => 'United Kingdom']),

                Filter::make('in_force')
                    ->label('Currently in force')
                    ->default()
                    ->query(fn (Builder $query) => $query
                        ->whereDate('effective_from', '<=', today())
                        ->where(fn (Builder $q) => $q->whereNull('effective_to')->whereDate('effective_to', '>', today()))),
            ])
            ->recordActions([
                EditAction::make(),

                // Superseding, rather than editing, is how a rate changes. The
                // old row keeps its dates so historic orders still explain
                // themselves; the new one takes over tomorrow.
                Action::make('supersede')
                    ->label('Supersede')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (TaxRate $rate) => $rate->effective_to === null)
                    ->schema(fn (TaxRate $rate) => [
                        TextInput::make('rate_bps')
                            ->label('New rate')
                            ->required()
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('0.01')
                            ->default($rate->rate_bps / 100),
                        DatePicker::make('effective_from')
                            ->label('New rate applies from')
                            ->required()
                            ->default(today()->addDay())
                            ->after(today()->subDay()),
                    ])
                    ->action(function (TaxRate $rate, array $data) {
                        $from = Carbon::parse($data['effective_from']);

                        $rate->update(['effective_to' => $from]);

                        TaxRate::create([
                            'country' => $rate->country,
                            'subdivision' => $rate->subdivision,
                            'default_currency' => $rate->default_currency,
                            'name' => $rate->name,
                            'rate_bps' => (int) round(((float) $data['rate_bps']) * 100),
                            'inclusive' => $rate->inclusive,
                            'effective_from' => $from,
                        ]);
                    })
                    ->requiresConfirmation()
                    ->modalDescription(
                        'The current rate is closed on the date you choose and a new one takes '
                        .'over. Orders already placed keep pointing at the old rate.'
                    ),
            ])
            // No bulk delete. A rate referenced by an order cannot be removed
            // without making that order unexplainable.
            ->toolbarActions([]);
    }
}
