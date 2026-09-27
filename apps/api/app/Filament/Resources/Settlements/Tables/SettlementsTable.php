<?php

namespace App\Filament\Resources\Settlements\Tables;

use App\Filament\Support\Listing;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every payout recorded, newest first.
 *
 * Amount and currency together, always, and the total at the foot is one
 * figure per currency: what went to Toronto organizers and what went to Lagos
 * ones are two numbers, not one.
 */
class SettlementsTable
{
    public const TYPES = ['full' => 'Full', 'partial' => 'Partial', 'overdraft' => 'Overdraft'];

    public const RAILS = [
        'interac' => 'Interac',
        'bank_transfer' => 'Bank transfer',
        'stripe' => 'Stripe',
        'paystack' => 'Paystack',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'success' => 'Success',
        'failed' => 'Failed',
        'reversed' => 'Reversed',
    ];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'settlements')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'organization:id,name,suspended_at',
                'event:id,title',
                'settledBy:id,name',
            ]))
            ->searchPlaceholder('Organization, event, note or who recorded it')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    // Nothing more goes to it until the suspension is lifted;
                    // SettlementRecorder refuses, and this says why up front.
                    ->description(fn ($record) => $record->organization?->isSuspended() ? 'Suspended — payouts frozen' : null),

                TextColumn::make('event.title')
                    ->label('Event')
                    ->placeholder('Across all events')
                    ->searchable()
                    ->toggleable(),

                // Amount and currency together, always. There is no column that
                // shows a number without saying what it is denominated in.
                Listing::money('amount', 'Amount')
                    ->weight('semibold')
                    ->summarize(Listing::totalsPerCurrency('amount')),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'full' => 'success',
                        'partial' => 'info',
                        'overdraft' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('rail')
                    ->label('Paid via')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => self::RAILS[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'success' => 'success',
                        'pending' => 'warning',
                        'failed', 'reversed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('settledBy.name')
                    ->label('Recorded by')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('settled_at')
                    ->label('Paid on')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->multiple()
                    ->options(self::TYPES),

                Listing::currency(),

                SelectFilter::make('status')
                    ->multiple()
                    ->options(self::STATUSES),

                SelectFilter::make('rail')
                    ->label('Paid via')
                    ->options(self::RAILS),

                SelectFilter::make('organization')
                    ->relationship('organization', 'name', fn (Builder $query) => $query->withTrashed())
                    ->searchable(),

                Listing::dateRange('recorded', 'created_at', 'Recorded'),
            ])
            // Read-only. Settlements are corrected by reversal, never edited.
            ->recordActions([])
            ->toolbarActions([]);
    }
}
