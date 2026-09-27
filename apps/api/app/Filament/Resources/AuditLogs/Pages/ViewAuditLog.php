<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;

    public function getTitle(): string
    {
        /** @var AuditLog $entry */
        $entry = $this->getRecord();

        return $entry->action;
    }

    /** Nothing: an entry is read, never changed. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
