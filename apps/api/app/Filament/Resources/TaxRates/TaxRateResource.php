<?php

namespace App\Filament\Resources\TaxRates;

use App\Enums\PlatformRole;
use App\Filament\Resources\TaxRates\Pages\CreateTaxRate;
use App\Filament\Resources\TaxRates\Pages\EditTaxRate;
use App\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Filament\Resources\TaxRates\Schemas\TaxRateForm;
use App\Filament\Resources\TaxRates\Tables\TaxRatesTable;
use App\Models\TaxRate;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Tax rates, administered per jurisdiction.
 *
 * Editing a rate that orders already point at would make those orders
 * unexplainable, so the table offers Supersede instead: the old row keeps its
 * dates, and a new row takes over from a chosen date.
 */
class TaxRateResource extends Resource
{
    protected static ?string $model = TaxRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Tax rates';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return TaxRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxRatesTable::configure($table);
    }

    /** Tax affects what every buyer is charged, so it sits with finance. */
    private static function canBeManagedBy(?User $user): bool
    {
        return $user?->hasPlatformRole(PlatformRole::Admin, PlatformRole::Finance) ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canBeManagedBy(auth()->user());
    }

    public static function canEdit($record): bool
    {
        return self::canBeManagedBy(auth()->user());
    }

    /** Never. A referenced rate cannot be removed; supersede it instead. */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * The same answers for the buttons as for the pages.
     *
     * Filament asks this, not the can* methods above, when it decides whether
     * to show New, Edit or Delete — and with no policy for tax rates it would
     * otherwise say yes to anybody on staff, and to deleting a rate at all.
     */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        $allowed = match ($action) {
            'viewAny', 'view' => $user instanceof User && $user->isPlatformStaff(),
            'create', 'update' => self::canBeManagedBy($user instanceof User ? $user : null),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxRates::route('/'),
            'create' => CreateTaxRate::route('/create'),
            'edit' => EditTaxRate::route('/{record}/edit'),
        ];
    }
}
