<?php

namespace App\Filament\Resources\PayoutDetails;

use App\Filament\Resources\PayoutDetails\Pages\ListPayoutDetails;
use App\Filament\Resources\PayoutDetails\Tables\PayoutDetailsTable;
use App\Models\OrganizationPayoutDetail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Where organizations are paid, and whether anybody has confirmed it.
 *
 * Settlement is manual: somebody here types these details into a bank. Before
 * this screen there was nowhere in the panel to read them, and nothing ever
 * set verified_at — so every payout would have gone to whatever an organizer's
 * account said that morning, including after somebody else got into it.
 *
 * Read-only apart from verifying. Organizers own their details; staff confirm
 * them. The list decrypts nothing, and opening one to verify it is logged.
 */
class PayoutDetailResource extends Resource
{
    protected static ?string $model = OrganizationPayoutDetail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Payout details';

    protected static ?string $modelLabel = 'payout destination';

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 15;

    public static function table(Table $table): Table
    {
        return PayoutDetailsTable::configure($table);
    }

    /** Unverified destinations, so the queue is visible without opening it. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::query()->whereNull('verified_at')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Banking details: administrators and finance, the same as settling. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->platform_role?->canReadSensitiveData() ?? false;
    }

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
            'index' => ListPayoutDetails::route('/'),
        ];
    }
}
