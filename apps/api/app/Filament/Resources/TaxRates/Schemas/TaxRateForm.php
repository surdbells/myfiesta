<?php

namespace App\Filament\Resources\TaxRates\Schemas;

use App\Models\TaxRate;
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
        /*
         * A rate that has priced a sale keeps its terms.
         *
         * Only its name can still be corrected here; the percentage, the
         * place, whether it sits inside the price and its dates are fixed,
         * and the list's Supersede button is how a rate in use changes. A
         * rate scheduled for the future and not yet used by anything can
         * still be fixed, but not moved into the past or onto days another
         * rate for its place covers. TaxRate and the database refuse the same
         * things underneath, for anything that does not come through here.
         */
        $fixed = fn (?TaxRate $record) => $record?->hasApplied() ?? false;

        /*
         * A scheduled replacement belongs to the rate it replaces: it keeps
         * that rate's place, and its start is the day that rate closes (the
         * edit page moves the two together). A rate something replaces ends
         * where the replacement starts, so its end is changed there.
         */
        $replacing = fn (?TaxRate $record) => $record?->replaces() !== null;
        $replaced = fn (?TaxRate $record) => $record?->isReplaced() ?? false;

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
                        ->disabled(fn (?TaxRate $record) => $fixed($record) || $replacing($record))
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
                        ->disabled(fn (?TaxRate $record) => $fixed($record) || $replacing($record))
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
                ->description(fn (?TaxRate $record) => $fixed($record)
                    ? 'This rate has already applied to sales, so only its name can be corrected. '
                        .'To change what it charges, use Supersede on the list: this rate is closed on a '
                        .'date and a new one takes over, and orders already placed keep this one.'
                    : null)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(64)
                        ->placeholder('HST')
                        ->helperText('Shown to buyers at checkout and on receipts.'),

                    TextInput::make('rate_bps')
                        ->label('Rate')
                        ->required()
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        ->disabled($fixed)
                        // Stored in basis points so the value that multiplies money
                        // stays an integer. 13% is 1300, not 0.13.
                        ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / 100)
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),

                    Toggle::make('inclusive')
                        ->label('Already included in the displayed price')
                        ->disabled($fixed)
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
                        ->disabled($fixed)
                        // Never backdated: orders placed before today were
                        // charged whatever applied then. TaxRate refuses it
                        // underneath as well.
                        ->minDate(fn (?TaxRate $record) => $fixed($record) ? null : today())
                        ->helperText(function (?TaxRate $record) use ($fixed) {
                            if ($fixed($record)) {
                                return null;
                            }

                            $before = $record?->replaces();

                            return $before
                                ? "Today or later. The {$before->name} rate this replaces closes on this day, and moves with it."
                                : 'Today or later.';
                        })
                        ->default(today()),

                    DatePicker::make('effective_to')
                        ->label('Superseded on')
                        ->after('effective_from')
                        ->disabled(fn (?TaxRate $record) => $fixed($record) || $replaced($record))
                        ->helperText(fn (?TaxRate $record) => $replaced($record)
                            ? 'Another rate takes over on this day. To move it, change that rate\'s start.'
                            : 'Blank means this is the rate currently in force.'),
                ])
                ->columns(2),
        ]);
    }
}
