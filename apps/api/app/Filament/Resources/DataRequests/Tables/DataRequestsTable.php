<?php

namespace App\Filament\Resources\DataRequests\Tables;

use App\Filament\Support\Listing;
use App\Models\DataRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What was asked, when, and what was done about it.
 *
 * The address is shown: this is the one screen where knowing which address
 * asked is the point, and every view of it is already in the panel's own
 * access log.
 */
class DataRequestsTable
{
    public const KINDS = ['export' => 'A copy', 'erasure' => 'Erasure'];

    public const STATUSES = [
        'pending' => 'Waiting on them',
        'verified' => 'In progress',
        'completed' => 'Done',
        'refused' => 'Refused',
        'expired' => 'Expired',
    ];

    /** Asked and proven, or asked and not yet proven: the clock is running on both. */
    private const OPEN = ['pending', 'verified'];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'privacy requests')
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Email address')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Asked')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Asked for')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'export' ? 'info' : 'warning')
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Address')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'refused' => 'danger',
                        'verified' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('verified_at')
                    ->label('Proven')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('completed_at')
                    ->label('Answered')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—')
                    ->description(fn (DataRequest $record) => $record->status === 'refused'
                        ? ($record->outcome['refused'] ?? null)
                        : null)
                    ->sortable()
                    ->wrap(),

                // Should never be set on anything unanswered. When it is, it is
                // the one number on this screen that matters.
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->color(fn (DataRequest $record) => self::isLate($record) ? 'danger' : 'gray')
                    ->weight(fn (DataRequest $record) => self::isLate($record) ? 'semibold' : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Asked for')
                    ->options(self::KINDS),

                SelectFilter::make('status')
                    ->multiple()
                    ->options(self::STATUSES),

                TernaryFilter::make('late')
                    ->label('Past the deadline')
                    ->trueLabel('Unanswered and past due')
                    ->falseLabel('On time, or answered')
                    ->queries(
                        true: fn (Builder $query) => $query
                            ->whereIn('status', self::OPEN)
                            ->whereNotNull('due_at')
                            ->where('due_at', '<', now()),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q
                            ->whereNotIn('status', self::OPEN)
                            ->orWhereNull('due_at')
                            ->orWhere('due_at', '>=', now())),
                    ),

                Listing::dateRange('asked', 'created_at', 'Asked'),

                Listing::dateRange('answered', 'completed_at', 'Answered'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function isLate(DataRequest $record): bool
    {
        return in_array($record->status, self::OPEN, true) && (bool) $record->due_at?->isPast();
    }
}
