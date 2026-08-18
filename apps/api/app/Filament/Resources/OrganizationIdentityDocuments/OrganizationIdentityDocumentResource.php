<?php

namespace App\Filament\Resources\OrganizationIdentityDocuments;

use App\Enums\PlatformRole;
use App\Filament\Resources\OrganizationIdentityDocuments\Pages\ListOrganizationIdentityDocuments;
use App\Filament\Resources\OrganizationIdentityDocuments\Pages\ViewOrganizationIdentityDocument;
use App\Filament\Resources\OrganizationIdentityDocuments\Tables\OrganizationIdentityDocumentsTable;
use App\Models\OrganizationIdentityDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The KYC review queue.
 *
 * The previous platform collected legal names, dates of birth, and government
 * identifiers, then never looked at them — a drawer rather than a decision.
 * Reviewing them is the whole point of this resource.
 *
 * Three constraints shape it. The record is read-only: nothing here is edited,
 * only approved or rejected with a reason. The columns are encrypted at rest,
 * so decrypting one is an event worth recording. And opening a record writes to
 * SensitiveDataAccess, because encryption protects against a stolen dump and
 * does nothing about someone with a valid login reading records they had no
 * business opening.
 */
class OrganizationIdentityDocumentResource extends Resource
{
    protected static ?string $model = OrganizationIdentityDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Identity review';

    protected static ?string $modelLabel = 'identity document';

    protected static string|\UnitEnum|null $navigationGroup = 'Trust and safety';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return OrganizationIdentityDocumentsTable::configure($table);
    }

    /** Awaiting review, so the queue length is visible without opening it. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->where('review_status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPlatformRole(
            PlatformRole::Admin,
            PlatformRole::Support,
        ) ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /** Documents arrive from organizers. Nobody creates or edits one here. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizationIdentityDocuments::route('/'),
            'view' => ViewOrganizationIdentityDocument::route('/{record}'),
        ];
    }
}
