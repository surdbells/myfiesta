<?php

namespace App\Filament\Resources\OrganizationIdentityDocuments\Tables;

use App\Models\OrganizationIdentityDocument;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The queue list.
 *
 * Deliberately shows nothing sensitive. Encrypted fields are decrypted only on
 * the detail page, where opening the record is logged — a list view that
 * decrypted every row would log nothing useful and expose a screenful of
 * government identifiers to anyone glancing at the screen.
 */
class OrganizationIdentityDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'passport' => 'Passport',
                        'drivers_licence' => 'Driving licence',
                        'national_id' => 'National ID',
                        default => $state,
                    }),

                TextColumn::make('submittedBy.name')
                    ->label('Submitted by')
                    ->placeholder('Unknown')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),

                TextColumn::make('review_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('reviewedBy.name')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Oldest pending first: a queue, not a feed.
            ->defaultSort('created_at')
            ->filters([
                SelectFilter::make('review_status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Awaiting review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('pending'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(fn (OrganizationIdentityDocument $record) => $record->review_status === 'pending'
                        ? 'Review'
                        : 'View'),
            ])
            ->toolbarActions([]);
    }
}
