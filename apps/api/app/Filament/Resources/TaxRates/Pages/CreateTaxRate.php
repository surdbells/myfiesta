<?php

namespace App\Filament\Resources\TaxRates\Pages;

use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\StaffSupport\TaxRateChanges;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxRate extends CreateRecord
{
    protected static string $resource = TaxRateResource::class;

    /**
     * A new rate is for days on which its place has no rate.
     *
     * Whether or not it has an end date: a rate from today until next year
     * beside the one in force would have checkout picking between two. The
     * database refuses it too; this says which rate is in the way. Changing
     * the rate in force is a supersession, and the list's Supersede button is
     * where that is done.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $clash = TaxRate::overlapping(
            $data['country'],
            $data['subdivision'] ?? null,
            $data['effective_from'],
            $data['effective_to'] ?? null,
        )->orderBy('effective_from')->first();

        if ($clash === null) {
            return $data;
        }

        self::refuse($clash);

        $this->halt();
    }

    protected function afterCreate(): void
    {
        $staff = auth()->user();

        if ($staff instanceof User) {
            app(TaxRateChanges::class)->recordCreated($this->getRecord(), $staff);
        }
    }

    /** Which rate already covers those days, and what to do instead. */
    public static function refuse(TaxRate $clash): void
    {
        $until = $clash->effective_to
            ? 'until '.$clash->effective_to->format('j M Y')
            : 'with no end date';

        Notification::make()
            ->title('That place already has a rate on those dates')
            ->body(
                "{$clash->name} applies there from {$clash->effective_from->format('j M Y')} {$until}, "
                .'and a place has one rate on any day. To change a rate in force, use Supersede on the list: '
                .'it closes on a date and the new rate takes over.'
            )
            ->danger()
            ->send();
    }
}
