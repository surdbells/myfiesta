<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Tickets\Actions\TicketRowActions;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Tickets this account holds now — bought, given, or transferred to them. */
class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Tickets held';

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'event:id,title,starts_at,timezone,organization_id',
                'ticketType:id,name',
                'order:id,reference,buyer_email',
            ]))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->searchPlaceholder('Holder name, email or order reference')
            ->columns(TicketsTable::columns())
            ->filters(array_values(array_filter(
                TicketsTable::filters(),
                fn ($filter) => in_array($filter->getName(), ['status', 'checked_in', 'event'], true),
            )))
            ->emptyStateHeading('Holds no tickets')
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->url(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record])),
                ActionGroup::make(TicketRowActions::all()),
            ])
            ->toolbarActions([]);
    }
}
