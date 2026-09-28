<?php

namespace App\Filament\Resources\TaxRates\Pages;

use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\StaffSupport\TaxRateChanges;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateTaxRate extends CreateRecord
{
    protected static string $resource = TaxRateResource::class;

    /** One rate at a time: each is asked about before it is added. */
    protected static bool $canCreateAnother = false;

    /**
     * The form submits to a question, not to create().
     *
     * A rate multiplies every buyer's total in its place from the day it
     * starts, so the button — and Enter in any box — asks first, naming the
     * rate, the place and the day. The form is checked before the question,
     * so a mistake is shown on the field rather than behind a modal.
     */
    protected function getSubmitFormLivewireMethodName(): string
    {
        return 'confirmCreate';
    }

    public function confirmCreate(): void
    {
        $this->form->validate();

        $this->mountAction('confirmCreate');
    }

    public function confirmCreateAction(): Action
    {
        return Action::make('confirmCreate')
            ->requiresConfirmation()
            ->modalHeading(fn () => 'Add '.self::summary($this->data ?? []).'?')
            ->modalDescription('Checkout charges it on every order in that place on the days it covers, on every organizer’s events. Orders already placed keep the rate they were charged. Recorded in the audit trail under your name.')
            ->modalSubmitActionLabel('Add the rate')
            ->action(fn () => $this->create());
    }

    /**
     * "HST at 13% in CA-ON from 1 Jan 2027", from the form as typed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function summary(array $data): string
    {
        $place = trim(($data['country'] ?? '').(filled($data['subdivision'] ?? null) ? '-'.$data['subdivision'] : ''), '-');
        $from = filled($data['effective_from'] ?? null) ? ' from '.Carbon::parse($data['effective_from'])->format('j M Y') : '';

        return ($data['name'] ?? 'the rate').' at '.($data['rate_bps'] ?? '?').'%'.($place !== '' ? ' in '.$place : '').$from;
    }

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
