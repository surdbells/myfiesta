<?php

namespace App\Filament\Resources\PayoutRequests;

use App\Filament\Resources\PayoutRequests\Pages\ListPayoutRequests;
use App\Filament\Resources\PayoutRequests\Tables\PayoutRequestsTable;
use App\Models\PayoutRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Organizers asking to be paid.
 *
 * The queue staff work from: pay a request, or reject it with a reason the
 * organizer will read. Paying is the only place an overdraft can be given:
 * confirmed on its own, with the figures and a written reason, and kept on
 * the request (see Overdrafts for how it comes back).
 */
class PayoutRequestResource extends Resource
{
    protected static ?string $model = PayoutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Payout requests';

    protected static ?string $modelLabel = 'payout request';

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return PayoutRequestsTable::configure($table);
    }

    /** Waiting requests, so the queue is visible from anywhere in the panel. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::query()->where('status', 'pending')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Administrators and finance: the people who can settle. */
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
            'index' => ListPayoutRequests::route('/'),
        ];
    }
}
