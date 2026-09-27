<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What this person bought while signed in.
 *
 * Orders placed as a guest under the same address are linked to the account
 * when they claim it; until then they are found from the Orders screen by
 * email.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Orders';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformStaff();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['event:id,title,starts_at,timezone', 'organization:id,name'])
                ->withSum(['refunds as refunded_amount' => fn (Builder $refunds) => $refunds->where('status', 'succeeded')], 'amount'))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->searchPlaceholder('Order reference')
            ->columns(OrdersTable::columns())
            ->filters(array_values(array_filter(
                OrdersTable::filters(withEvent: false),
                fn ($filter) => in_array($filter->getName(), ['status', 'currency', 'placed'], true),
            )))
            ->emptyStateHeading('No orders on this account')
            ->emptyStateDescription('Guest orders under this address show on the Orders screen until the account claims them.')
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->url(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record])),
                ActionGroup::make([OrderActions::resendTickets(), OrderActions::addNote()]),
            ])
            ->toolbarActions([]);
    }
}
