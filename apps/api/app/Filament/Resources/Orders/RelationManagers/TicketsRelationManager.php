<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Tickets\Actions\TicketRowActions;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The tickets on one order, where support picks which ones to refund.
 *
 * Codes masked, as everywhere in the panel. Ticking tickets and choosing
 * "Refund selected" sends exactly those through RefundService.
 */
class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Tickets';

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ticketType:id,name', 'order:id,reference,buyer_email']))
            ->defaultSort('created_at')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder('Holder name or email')
            ->columns(TicketsTable::columns(withEvent: false, withOrder: false))
            ->filters([
                ...array_filter(TicketsTable::filters(withEvent: false), fn ($filter) => in_array($filter->getName(), ['status', 'checked_in'], true)),
            ])
            ->emptyStateHeading('No tickets on this order')
            ->emptyStateDescription('A payment that has not completed, or an order for add-ons only.')
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->url(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record])),
                ActionGroup::make(TicketRowActions::all()),
            ])
            ->toolbarActions([
                OrderActions::refundSelectedTickets()
                    ->visible(fn () => $this->getOwnerRecord() instanceof Order && OrderActions::refundable($this->getOwnerRecord())),
            ])
            ->checkIfRecordIsSelectableUsing(fn (Ticket $record) => ! in_array($record->status, ['refunded', 'void'], true));
    }
}
