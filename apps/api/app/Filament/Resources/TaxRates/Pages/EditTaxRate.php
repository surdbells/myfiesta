<?php

namespace App\Filament\Resources\TaxRates\Pages;

use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\StaffSupport\TaxRateChanges;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditTaxRate extends EditRecord
{
    protected static string $resource = TaxRateResource::class;

    /** The rate this one replaces, when moving its start moves that rate's end. */
    protected ?TaxRate $closesWithIt = null;

    /** No delete: a rate orders point at is superseded, never removed. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * An edit may not leave the place with two rates on one day, nor with a
     * day between a rate and its replacement.
     *
     * Only a rate nothing has used yet gets this far with its dates open,
     * and TaxRate keeps its start from moving into the past. A scheduled
     * replacement's start is the day the rate before it closes, so the two
     * move together: later, and the old rate runs on until the new one
     * starts; earlier, and it closes sooner. Left apart, the days between
     * would fall back to the country-wide rate — 5% in Ontario instead of
     * 13%.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var TaxRate $rate */
        $rate = $this->getRecord();
        $proposed = (clone $rate)->fill($data);

        $before = $proposed->isDirty('effective_from') ? $rate->replaces() : null;

        if ($before !== null && $proposed->effective_from->lte($before->effective_from)) {
            Notification::make()
                ->title('A replacement starts after the rate it replaces')
                ->body("This rate takes over from {$before->name}, which started on {$before->effective_from->format('j M Y')}.")
                ->danger()
                ->send();

            $this->halt();
        }

        $clash = TaxRate::overlapping(
            $proposed->country,
            $proposed->subdivision,
            $proposed->effective_from,
            $proposed->effective_to,
        )
            ->whereKeyNot($rate->getKey())
            ->when($before, fn ($q) => $q->whereKeyNot($before->getKey()))
            ->orderBy('effective_from')
            ->first();

        if ($clash !== null) {
            CreateTaxRate::refuse($clash);

            $this->halt();
        }

        $this->closesWithIt = $before;

        return $data;
    }

    /**
     * Whichever rate gives way goes first, so the two never share a day even
     * for one statement: the database checks each one.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TaxRate $record */
        return DB::transaction(function () use ($record, $data) {
            $record->fill($data);
            $before = $this->closesWithIt;

            if ($before !== null && $record->effective_from->lt($before->effective_to)) {
                $before->update(['effective_to' => $record->effective_from]);
                $record->save();
            } else {
                $record->save();
                $before?->update(['effective_to' => $record->effective_from]);
            }

            return $record;
        });
    }

    /** What changed, by whom: a rate multiplies every buyer's total. */
    protected function afterSave(): void
    {
        $staff = auth()->user();

        if ($staff instanceof User) {
            app(TaxRateChanges::class)->recordEdited($this->getRecord(), $staff);

            if ($this->closesWithIt !== null) {
                app(TaxRateChanges::class)->recordEdited($this->closesWithIt, $staff);
            }
        }
    }
}
