<?php

namespace App\Filament\Resources\Tickets;

use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\Schemas\TicketInfolist;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Filament\Support\ReadOnlyForStaff;
use App\Models\Ticket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Tickets, for "I never got mine", "it says already used" and "can it go to
 * my friend".
 *
 * Not globally searchable, on purpose: global search would be one more place
 * a code could be typed in to see whose ticket it is.
 */
class TicketResource extends Resource
{
    use ReadOnlyForStaff;

    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 30;

    protected static bool $isGloballySearchable = false;

    public static function table(Table $table): Table
    {
        return TicketsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TicketInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
