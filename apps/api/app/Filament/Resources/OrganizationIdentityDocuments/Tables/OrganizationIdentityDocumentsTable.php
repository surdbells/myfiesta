<?php

namespace App\Filament\Resources\OrganizationIdentityDocuments\Tables;

use App\Filament\Support\Listing;
use App\Models\OrganizationIdentityDocument;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The queue list.
 *
 * Deliberately shows nothing sensitive. Encrypted fields are decrypted only on
 * the detail page, where opening the record is logged — a list view that
 * decrypted every row would log nothing useful and expose a screenful of
 * government identifiers to anyone glancing at the screen. For the same
 * reason nothing encrypted is searchable or sortable: search runs on the
 * organization and the person who sent it in.
 */
class OrganizationIdentityDocumentsTable
{
    public const TYPES = [
        'passport' => 'Passport',
        'drivers_licence' => 'Driving licence',
        'national_id' => 'National ID',
    ];

    public const STATUSES = [
        'pending' => 'Awaiting review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'identity documents')
            // Only the columns the list shows: the encrypted ones stay in the
            // database until somebody opens a record, where that is logged.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select([
                    'organization_identity_documents.id',
                    'organization_identity_documents.organization_id',
                    'organization_identity_documents.submitted_by',
                    'organization_identity_documents.document_type',
                    'organization_identity_documents.review_status',
                    'organization_identity_documents.reviewed_by',
                    'organization_identity_documents.reviewed_at',
                    'organization_identity_documents.created_at',
                    'organization_identity_documents.updated_at',
                ])
                ->with(['organization:id,name', 'submittedBy:id,name,email', 'reviewedBy:id,name']))
            ->searchPlaceholder('Organization or who sent it')
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('submittedBy.name')
                    ->label('Submitted by')
                    ->searchable(['name', 'email'])
                    ->description(fn (OrganizationIdentityDocument $record) => $record->submittedBy?->email)
                    ->placeholder('Unknown')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->dateTimeTooltip('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('review_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('reviewedBy.name')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Oldest pending first: a queue, not a feed.
            ->defaultSort('created_at')
            ->filters([
                SelectFilter::make('review_status')
                    ->label('Status')
                    ->options(self::STATUSES)
                    ->default('pending'),

                SelectFilter::make('document_type')
                    ->label('Type')
                    ->options(self::TYPES),

                Listing::dateRange('submitted', 'created_at', 'Submitted'),

                Listing::dateRange('reviewed', 'reviewed_at', 'Reviewed'),
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
