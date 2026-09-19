<?php

namespace App\Filament\Resources\DataRequests\Tables;

use App\Models\DataRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * What was asked, when, and what was done about it.
 *
 * The address is shown: this is the one screen where knowing which address
 * asked is the point, and every view of it is already in the panel's own
 * access log.
 */
class DataRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Asked')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Asked for')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'export' ? 'A copy' : 'Erasure')
                    ->color(fn (string $state) => $state === 'export' ? 'info' : 'warning'),

                TextColumn::make('email')
                    ->label('Address')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Waiting on them',
                        'verified' => 'In progress',
                        'completed' => 'Done',
                        'refused' => 'Refused',
                        default => 'Expired',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'refused' => 'danger',
                        'verified' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('completed_at')
                    ->label('Answered')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—')
                    ->description(fn (DataRequest $record) => $record->status === 'refused'
                        ? ($record->outcome['refused'] ?? null)
                        : null)
                    ->wrap(),

                // Should never be set on anything unanswered. When it is, it is
                // the one number on this screen that matters.
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->color(fn (DataRequest $record) => in_array($record->status, ['pending', 'verified'], true)
                        && $record->due_at?->isPast() ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(['export' => 'A copy', 'erasure' => 'Erasure']),
                SelectFilter::make('status')->options([
                    'pending' => 'Waiting on them',
                    'verified' => 'In progress',
                    'completed' => 'Done',
                    'refused' => 'Refused',
                    'expired' => 'Expired',
                ]),
            ]);
    }
}
