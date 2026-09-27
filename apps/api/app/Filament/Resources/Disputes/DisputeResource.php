<?php

namespace App\Filament\Resources\Disputes;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Chargebacks, and answering them.
 *
 * When a processor opens a dispute the answer is already put together from
 * the records (DisputeDesk), and this is where staff read it, correct it and
 * send it before the deadline — or accept it, when the buyer is right. The
 * list is ordered by what needs answering first.
 *
 * Every member of staff can read it: Support is often who the buyer or the
 * organizer rings. Only Admin and Finance can send evidence, accept a dispute
 * or open the documents, the same split as refunds; the page hides what a
 * role cannot do and DisputeDesk refuses it again behind the button.
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
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformStaff() && $user->deleted_at === null;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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
            'view' => ViewDispute::route('/{record}'),
        ];
    }
}
