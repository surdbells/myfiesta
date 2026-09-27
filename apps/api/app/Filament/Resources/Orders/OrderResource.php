<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Filament\Support\Listing;
use App\Filament\Support\ReadOnlyForStaff;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders across every organizer.
 *
 * Support reads these; finance and administrators can also refund them, only
 * ever through RefundService. Ticket codes never appear — not in the list, not
 * on the order, not in the refund form.
 */
class OrderResource extends Resource
{
    use ReadOnlyForStaff;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'buyer_email', 'buyer_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return 'Order '.$record->reference;
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Buyer' => $record->buyer_name,
            'Total' => Listing::format((int) $record->total_amount, $record->currency),
            'Status' => OrdersTable::STATUSES[$record->status] ?? $record->status,
        ]);
    }

    public static function getRelations(): array
    {
        return [
            TicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
