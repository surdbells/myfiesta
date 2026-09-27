<?php

namespace App\Filament\Pages\Concerns;

use App\Services\Analytics\Market;
use App\Services\Analytics\Period;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The market and period pickers every performance page shares.
 *
 * One currency at a time, by construction: the picker is a toggle between
 * the markets, not a multi-select, so no screen can be asked for a total
 * across naira and dollars. The period is a preset or a custom range, read
 * in that market's own clock.
 *
 * Used by pages with Filament's filters form, whose state lives in
 * $this->filters and is kept in the query string and the session.
 */
trait ReadsAnalyticsFilters
{
    /** @return list<Component> */
    protected function periodFields(): array
    {
        return [
            ToggleButtons::make('currency')
                ->label('Market')
                ->options(array_combine(array_keys(Market::CURRENCIES), array_keys(Market::CURRENCIES)))
                ->default(Market::DEFAULT)
                ->inline()
                ->grouped(),

            Select::make('period')
                ->label('Period')
                ->options(Period::OPTIONS)
                ->default(Period::DEFAULT)
                ->selectablePlaceholder(false),

            DatePicker::make('from')
                ->label('From')
                ->visible(fn (Get $get): bool => $get('period') === 'custom')
                ->maxDate(now()),

            DatePicker::make('until')
                ->label('Until')
                ->visible(fn (Get $get): bool => $get('period') === 'custom')
                ->maxDate(now()),
        ];
    }

    public function analyticsCurrency(): string
    {
        return Market::normalize($this->filters['currency'] ?? null);
    }

    public function analyticsPeriod(): Period
    {
        return Period::fromFilters($this->filters, Market::timezone($this->analyticsCurrency()));
    }

    /** "1 Sep – 30 Sep 2026 in CAD, against the 30 days before" */
    public function periodSummary(): string
    {
        $period = $this->analyticsPeriod();

        return $period->label().' in '.$this->analyticsCurrency().', on '.$period->timezone.' time, against the '.$period->days().' days before.';
    }
}
