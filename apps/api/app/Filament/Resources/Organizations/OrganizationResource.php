<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Tables\OrganizationsTable;
use App\Models\Organization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Organizations, and what they are owed.
 *
 * The balance column is the reason this resource carries the settle action:
 * an amount paid only means something next to the amount outstanding, and
 * classifying it as full, partial, or overdraft is arithmetic rather than a
 * choice from a dropdown.
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return OrganizationsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
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

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
        ];
    }
}
