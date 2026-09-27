<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\Actions\TicketRowActions;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return ($record->ticketType?->name ?? 'Ticket').' — '.($record->holder_name ?: $record->owner_email ?: 'no name');
    }

    protected function getHeaderActions(): array
    {
        return array_map(
            fn (Action $action) => $action->after(fn () => $this->getRecord()->refresh()),
            TicketRowActions::all(),
        );
    }
}
