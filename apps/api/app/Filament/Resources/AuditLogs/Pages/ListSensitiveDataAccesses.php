<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\SensitiveDataAccessResource;
use Filament\Resources\Pages\ListRecords;

class ListSensitiveDataAccesses extends ListRecords
{
    protected static string $resource = SensitiveDataAccessResource::class;

    /** Written by the screens that show the data, never from here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
