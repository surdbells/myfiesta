<?php

namespace App\Filament\Resources\Settlements;

use App\Enums\PlatformRole;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Settlements\Tables\SettlementsTable;
use App\Models\Settlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Payouts to organizers.
 *
 * Settlement is manual, so a row here asserts that money moved in the real
 * world rather than instructing a processor to move it. That makes it the
 * narrowest permission on the platform and the one where a mistake is hardest
 * to walk back.
 *
 * Records are created through an action that reads the live balance and
 * classifies the amount against it, never through a blank form — the amount
 * only means something relative to what is owed. Nothing is editable: a
 * settlement is corrected by reversal, like the ledger it writes to.
 */
class SettlementResource extends Resource
{
    protected static ?string $model = Settlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return SettlementsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPlatformRole(
            PlatformRole::Admin,
            PlatformRole::Finance,
        ) ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /** Created from the organization balance view, where the amount has context. */
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
            'index' => ListSettlements::route('/'),
        ];
    }
}
