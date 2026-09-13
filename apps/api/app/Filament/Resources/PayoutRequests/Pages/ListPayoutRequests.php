<?php

namespace App\Filament\Resources\PayoutRequests\Pages;

use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPayoutRequests extends ListRecords
{
    protected static string $resource = PayoutRequestResource::class;

    /** Requests arrive from organizers; nothing is created here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
