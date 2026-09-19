<?php

namespace App\Filament\Resources\Disputes\Pages;

use App\Filament\Resources\Disputes\DisputeResource;
use Filament\Resources\Pages\ListRecords;

class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    /** They arrive from a processor; nothing is raised here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
