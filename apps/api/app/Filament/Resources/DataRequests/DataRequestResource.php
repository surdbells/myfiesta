<?php

namespace App\Filament\Resources\DataRequests;

use App\Filament\Resources\DataRequests\Pages\ListDataRequests;
use App\Filament\Resources\DataRequests\Tables\DataRequestsTable;
use App\Models\DataRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Privacy requests, and whether they were answered in time.
 *
 * Read-only on purpose. Staff do not action these — the platform does, the
 * moment the person proves the address — so the panel's job is to show that
 * they are being answered, and to make a regulator's question ("show me your
 * requests from last year") a screen rather than a project.
 */
class DataRequestResource extends Resource
{
    protected static ?string $model = DataRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Privacy requests';

    protected static ?string $modelLabel = 'privacy request';

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return DataRequestsTable::configure($table);
    }

    /**
     * Anything past the thirty days the law allows.
     *
     * Should always be nothing: requests are carried out on the spot. A number
     * here means something is stuck, which is exactly when it needs to be
     * loud.
     */
    public static function getNavigationBadge(): ?string
    {
        $late = static::getModel()::query()
            ->whereIn('status', ['pending', 'verified'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        return $late > 0 ? (string) $late : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** Staff, not organizers: these are requests about the platform's own records. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->platform_role !== null;
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
            'index' => ListDataRequests::route('/'),
        ];
    }
}
