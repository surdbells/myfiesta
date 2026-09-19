<?php

namespace App\Filament\Resources\DataRequests\Pages;

use App\Filament\Resources\DataRequests\DataRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDataRequests extends ListRecords
{
    protected static string $resource = DataRequestResource::class;

    /** They arrive from people, and the platform answers them. Nothing to add here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
