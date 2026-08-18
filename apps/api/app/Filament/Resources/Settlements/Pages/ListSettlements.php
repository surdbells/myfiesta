<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Resources\Settlements\SettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;

    /**
     * No create button.
     *
     * A settlement amount only means something next to the balance it is paying
     * down, so it is recorded from the organization view where that balance is
     * on screen and can classify the amount.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
