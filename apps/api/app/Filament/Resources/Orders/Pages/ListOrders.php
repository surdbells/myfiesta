<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** Orders come from checkout and the door, never from here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
