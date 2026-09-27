<?php

namespace App\Filament\Resources\TaxRates\Pages;

use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\User;
use App\Services\StaffSupport\TaxRateChanges;
use Filament\Resources\Pages\EditRecord;

class EditTaxRate extends EditRecord
{
    protected static string $resource = TaxRateResource::class;

    /** No delete: a rate orders point at is superseded, never removed. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /** What changed, by whom: a rate multiplies every buyer's total. */
    protected function afterSave(): void
    {
        $staff = auth()->user();

        if ($staff instanceof User) {
            app(TaxRateChanges::class)->recordEdited($this->getRecord(), $staff);
        }
    }
}
