<?php

namespace App\Filament\Resources\TaxRates\Pages;

use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\User;
use App\Services\StaffSupport\TaxRateChanges;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxRate extends CreateRecord
{
    protected static string $resource = TaxRateResource::class;

    protected function afterCreate(): void
    {
        $staff = auth()->user();

        if ($staff instanceof User) {
            app(TaxRateChanges::class)->recordCreated($this->getRecord(), $staff);
        }
    }
}
