<?php

namespace App\Filament\Resources\PayoutDetails\Pages;

use App\Filament\Resources\PayoutDetails\PayoutDetailResource;
use Filament\Resources\Pages\ListRecords;

class ListPayoutDetails extends ListRecords
{
    protected static string $resource = PayoutDetailResource::class;

    /** Nothing is created here. Details arrive from organizers. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
