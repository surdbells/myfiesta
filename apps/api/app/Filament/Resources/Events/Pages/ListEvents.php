<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    /** Events are made by organizers, in the console. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
