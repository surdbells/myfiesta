<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\Schemas\OrganizationInfolist;
use App\Filament\Resources\Organizations\Tables\OrganizationsTable;
use App\Filament\Support\ReadOnlyForStaff;
use App\Models\Organization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Organizations, and what they are owed.
 *
 * The balance column is the reason this resource carries the settle action:
 * an amount paid only means something next to the amount outstanding, and
 * classifying it as full, partial, or overdraft is arithmetic rather than a
 * choice from a dropdown.
 *
 * Every member of staff may look; nothing is edited in place. What is done to
 * an organization — settling, confirming a new name, acting as it, suspending
 * it — is a named action gated by role and written to the audit trail.
 * Authorised here rather than by OrganizationPolicy, which answers organizer
 * questions: a platform employee is not a member of anything (ReadOnlyForStaff).
 */
class OrganizationResource extends Resource
{
    use ReadOnlyForStaff;

    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return OrganizationsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrganizationInfolist::configure($schema);
    }

    /** Organizations register themselves. Nobody creates one from here. */
    public static function canCreate(): bool
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

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug', 'contact_email'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Page' => '/o/'.$record->slug,
            'Standing' => $record instanceof Organization && $record->isSuspended() ? 'Suspended' : null,
        ]);
    }

    /** Suspended ones, so an operator sees at a glance that some are. */
    public static function getNavigationBadge(): ?string
    {
        $suspended = Organization::query()->whereNotNull('suspended_at')->count();

        return $suspended > 0 ? $suspended.' suspended' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}
