<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Actions\EventActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Action::make('orders')
                ->label('Orders')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->url(OrderResource::getUrl('index', ['filters' => ['event' => ['value' => $record->getKey()]]])),

            Action::make('tickets')
                ->label('Tickets')
                ->icon(Heroicon::OutlinedTicket)
                ->color('gray')
                ->url(TicketResource::getUrl('index', ['filters' => ['event' => ['value' => $record->getKey()]]])),

            EventActions::publicPage(),

            ActionGroup::make(array_map(
                fn (Action $action) => $action->after(fn () => $this->getRecord()->refresh()),
                EventActions::all(),
            ))
                ->label('Moderate')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button(),
        ];
    }
}
