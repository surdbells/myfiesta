<?php

namespace App\Filament\Resources\Staff;

use App\Enums\PlatformRole;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\Tables\StaffTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Who works here, and what they may do in the admin.
 *
 * Administrators only. Finance and Support do not see it in the navigation
 * and are refused the page: deciding who can read bank details is not a thing
 * somebody who can read bank details should be able to do for themselves.
 *
 * Nothing is created or edited through a form. Access is granted to an
 * account that already exists (the header action), and every change goes
 * through StaffAccess, which holds the rules and writes the audit trail.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?string $navigationLabel = 'Staff';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 90;

    /**
     * Staff only. The same table holds every ticket buyer.
     *
     * Deactivated accounts that still hold a role are included, behind the
     * table's filter: reactivating one would give its access back, so it has
     * to be findable here to be revoked.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereNotNull('platform_role');
    }

    public static function table(Table $table): Table
    {
        return StaffTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPlatformRole(PlatformRole::Admin) ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /** Access is granted to an existing account, never by creating one here. */
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

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return collect(PlatformRole::cases())
            ->mapWithKeys(fn (PlatformRole $role) => [$role->value => $role->label()])
            ->all();
    }

    /**
     * What each role reaches, in the words somebody choosing one needs.
     *
     * @return array<string, string>
     */
    public static function roleDescriptions(): array
    {
        return collect(PlatformRole::cases())
            ->mapWithKeys(fn (PlatformRole $role) => [$role->value => match ($role) {
                PlatformRole::Admin => 'Everything, including this screen: who works here and what they can do.',
                PlatformRole::Finance => 'Settlements, payout requests, disputes and organizers’ bank details. Not identity documents.',
                PlatformRole::Support => 'Organizers, identity documents and privacy requests. Not bank details or settlements.',
            }])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
        ];
    }
}
