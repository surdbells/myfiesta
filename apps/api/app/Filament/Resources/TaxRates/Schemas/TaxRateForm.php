<?php

namespace App\Filament\Resources\TaxRates\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaxRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Jurisdiction')
                ->description(
                    'Rates are keyed on where the event happens, not on its currency. '
                    .'Currency looks like a workable key because CAD implies Canada, and it '
                    .'is wrong for most of Canada: rates vary by province, while Nigerian '
                    .'VAT is flat.'
                )
                ->schema([
                    Select::make('country')
                        ->required()
                        ->searchable()
                        ->options([
                            'CA' => 'Canada',
                            'NG' => 'Nigeria',
                            'US' => 'United States',
                            'GB' => 'United Kingdom',
                        ]),

                    TextInput::make('subdivision')
                        ->label('Province or state')
                        ->maxLength(8)
                        ->placeholder('ON')
                        ->helperText(
                            'Leave blank for a country-wide rate. A province-specific '
                            .'row wins over the country fallback.'
                        ),

                    Select::make('default_currency')
                        ->label('Currency hint')
                        ->options(['CAD' => 'CAD', 'NGN' => 'NGN', 'USD' => 'USD', 'GBP' => 'GBP'])
                        ->helperText('Used only to pre-fill lookups. It does not decide the rate.'),
                ])
                ->columns(3),

            Section::make('Rate')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(64)
                        ->placeholder('HST')
                        ->helperText('Shown to buyers at checkout.'),

                    TextInput::make('rate_bps')
                        ->label('Rate')
                        ->required()
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        // Stored in basis points so the value that multiplies money
                        // stays an integer. 13% is 1300, not 0.13.
                        ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / 100)
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),

                    Toggle::make('inclusive')
                        ->label('Already included in the displayed price')
                        ->helperText('Off means it is added at checkout.'),
                ])
                ->columns(3),

            Section::make('Effective dates')
                ->description(
                    'Rates are superseded, never edited. A historic order has to stay '
                    .'reproducible against the rate that applied when it was placed.'
                )
                ->schema([
                    DatePicker::make('effective_from')
                        ->required()
                        ->default(today()),

                    DatePicker::make('effective_to')
                        ->label('Superseded on')
                        ->after('effective_from')
                        ->helperText('Blank means this is the rate currently in force.'),
                ])
                ->columns(2),
        ]);
    }
}
