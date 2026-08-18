<?php

namespace App\Filament\Resources\Settlements\Tables;

use App\Models\Settlement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SettlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('event.title')
                    ->label('Event')
                    ->placeholder('Across all events')
                    ->searchable()
                    ->toggleable(),

                // Amount and currency together, always. There is no column that
                // shows a number without saying what it is denominated in.
                TextColumn::make('amount')
                    ->label('Amount')
                    ->alignEnd()
                    ->weight('semibold')
                    ->state(fn (Settlement $record) => self::format($record->amount, $record->currency))
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'full' => 'success',
                        'partial' => 'info',
                        'overdraft' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('rail')
                    ->label('Paid via')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'interac' => 'Interac',
                        'bank_transfer' => 'Bank transfer',
                        'stripe' => 'Stripe',
                        'paystack' => 'Paystack',
                        default => $state,
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'success' => 'success',
                        'pending' => 'warning',
                        'failed', 'reversed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('settledBy.name')
                    ->label('Recorded by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'full' => 'Full',
                        'partial' => 'Partial',
                        'overdraft' => 'Overdraft',
                    ]),

                SelectFilter::make('currency')
                    ->options(['CAD' => 'CAD', 'NGN' => 'NGN']),

                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'reversed' => 'Reversed',
                    ]),
            ])
            // Read-only. Settlements are corrected by reversal, never edited.
            ->recordActions([])
            ->toolbarActions([]);
    }

    /** Minor units to a readable amount, with the currency always attached. */
    private static function format(int $minorUnits, string $currency): string
    {
        return $currency.' '.number_format($minorUnits / 100, 2);
    }
}
