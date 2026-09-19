<?php

namespace App\Filament\Resources\Disputes\Tables;

use App\Models\Dispute;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The chargeback list: who, how much, why, and how long is left.
 */
class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization:id,name', 'order:id,reference', 'event:id,title']))
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('opened_at')
                    ->label('Raised')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('organization.name')
                    ->label('Organizer')
                    ->searchable()
                    ->description(fn (Dispute $record) => $record->event?->title),

                TextColumn::make('order.reference')
                    ->label('Order')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn (Dispute $record) => (new Money((int) $record->amount, $record->currency))->format())
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Their reason')
                    ->formatStateUsing(fn (?string $state) => $state ? str_replace('_', ' ', $state) : '—')
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'open' => 'Open',
                        'won' => 'Kept the money',
                        'lost' => 'Money taken back',
                        default => 'Withdrawn',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'won' => 'success',
                        'lost' => 'danger',
                        'open' => 'warning',
                        default => 'gray',
                    }),

                // The only date that can still be acted on. After it, nobody
                // can do anything about this one.
                TextColumn::make('evidence_due_at')
                    ->label('Answer by')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->color(fn (Dispute $record) => $record->isOpen() && $record->evidence_due_at?->isPast() ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Open',
                    'won' => 'Kept the money',
                    'lost' => 'Money taken back',
                    'withdrawn' => 'Withdrawn',
                ]),
                SelectFilter::make('gateway')->options(['stripe' => 'Stripe', 'paystack' => 'Paystack']),
            ]);
    }
}
