<?php

namespace App\Filament\Resources\Disputes;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Chargebacks, and who is answering them.
 *
 * The evidence is submitted in the processor's own dashboard — Stripe and
 * Paystack both want the files there, and duplicating that here would be a
 * second place to keep in step. What this screen is for is knowing a dispute
 * exists at all, which organizer it belongs to, and how long is left to
 * answer it.
 */
class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Chargebacks';

    protected static ?string $modelLabel = 'chargeback';

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return DisputesTable::configure($table);
    }

    /** Open ones, because every one of them has a clock on it. */
    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::query()->where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->platform_role?->canSettle() ?? false;
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
            'index' => ListDisputes::route('/'),
        ];
    }
}
