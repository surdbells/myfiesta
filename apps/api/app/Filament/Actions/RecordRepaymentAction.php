<?php

namespace App\Filament\Actions;

use App\Filament\Support\Listing;
use App\Models\Organization;
use App\Models\User;
use App\Services\Payouts\OverdraftPosition;
use App\Services\Payouts\Overdrafts;
use App\Services\Payouts\RepaymentRefused;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Record money an organization paid back to myFiesta outside the platform.
 *
 * For an advance, or a shortfall after refunds, repaid by a transfer to us
 * rather than by sales. It asserts that money arrived in our account, so it
 * is for the people who record payouts; it asks for the reference the money
 * arrived with and a reason, says what it will do before doing it, and is
 * never more than is outstanding. Overdrafts checks all of that again.
 *
 * On the organization's page, and on each row of the overdrafts list, where
 * the record is the row rather than an organization.
 */
class RecordRepaymentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'recordRepayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Record a repayment')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn ($record): bool => (auth()->user()?->platform_role?->canSettle() ?? false)
                && self::owing($record) !== [])
            ->modalIcon('heroicon-o-arrow-uturn-left')
            ->modalHeading(fn ($record): string => 'Record a repayment from '.(self::organizationOf($record)->name ?? 'this organization'))
            ->modalDescription('For money the organization paid back to myFiesta outside the platform, such as a bank transfer or an e-Transfer to us. '
                .'It is written to their ledger as a repayment and lowers what they owe by that much. No money moves, and it cannot be edited afterwards.')
            ->fillForm(fn ($record): array => [
                'currency' => is_array($record) && isset($record['currency'])
                    ? $record['currency']
                    : array_key_first(self::owing($record)),
            ])
            ->schema(fn ($record): array => [
                Select::make('currency')
                    ->label('Currency')
                    ->required()
                    ->live()
                    ->options(collect(self::owing($record))
                        ->mapWithKeys(fn (OverdraftPosition $position, string $currency) => [
                            $currency => $currency.' — '.$position->outstanding->format().' outstanding',
                        ])
                        ->all()),

                TextInput::make('amount')
                    ->label('Amount received')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->step('0.01')
                    ->live(onBlur: true)
                    ->prefix(fn (Get $get) => Listing::prefix($get('currency')))
                    ->helperText(function (Get $get) use ($record): string {
                        $position = self::owing($record)[(string) $get('currency')] ?? null;

                        return $position
                            ? 'Up to '.$position->outstanding->format().', what is outstanding. '.$position->summary()
                            : 'Choose the currency it was paid in.';
                    }),

                TextInput::make('reference')
                    ->label('Reference')
                    ->required()
                    ->maxLength(120)
                    ->helperText('The bank’s or Interac’s reference for the money arriving, so this can be matched to our statement. Each reference is recorded once.'),

                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(1000)
                    ->rows(3)
                    ->helperText('Who paid it and why, for whoever reads the audit trail later.'),

                /*
                 * What pressing the button will do, in figures, beside the
                 * button: the confirmation is this form, so it names the
                 * amount and what is left owing before anything is written.
                 */
                Placeholder::make('effect')
                    ->label('This will record')
                    ->content(function (Get $get) use ($record): string {
                        $position = self::owing($record)[(string) $get('currency')] ?? null;
                        $typed = (float) $get('amount');

                        if ($position === null || $typed <= 0) {
                            return 'Enter the amount received.';
                        }

                        $amount = new Money((int) round($typed * 100), $position->currency);

                        if ($amount->amount > $position->outstanding->amount) {
                            return 'More than is outstanding ('.$position->outstanding->format().'). Record up to that amount.';
                        }

                        $left = new Money($position->outstanding->amount - $amount->amount, $position->currency);

                        return 'A repayment of '.$amount->format().' from '.(self::organizationOf($record)->name ?? 'this organization')
                            .'. What they owe in '.$position->currency.' goes from '.$position->outstanding->format().' to '
                            .($left->isZero() ? 'nothing' : $left->format()).'.';
                    }),

                Checkbox::make('received')
                    ->label('The money is in myFiesta’s account')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Record it once the money has arrived.']),
            ])
            ->modalSubmitActionLabel('Record repayment')
            ->action(function ($record, array $data, Action $action): void {
                $organization = self::organizationOf($record);
                $staff = auth()->user();

                if ($organization === null || ! $staff instanceof User) {
                    abort(403);
                }

                $currency = (string) $data['currency'];
                $amount = new Money((int) round(((float) $data['amount']) * 100), $currency);

                try {
                    app(Overdrafts::class)->recordRepayment($organization, $staff, $amount, (string) $data['reference'], (string) $data['reason']);
                } catch (RepaymentRefused $refused) {
                    Notification::make()->title('Not recorded')->body($refused->getMessage())->danger()->send();

                    // The form stays open with what was typed, to correct.
                    $action->halt();
                }

                $left = app(Overdrafts::class)->position($organization, $currency);

                Notification::make()
                    ->title('Repayment recorded')
                    ->body($amount->format().' from '.$organization->name.'. '
                        .($left?->isOutstanding() ? $left->outstanding->format().' is still outstanding.' : 'Nothing more is outstanding.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The currencies this organization owes in, and where each stands.
     *
     * @return array<string, OverdraftPosition>
     */
    private static function owing(mixed $record): array
    {
        $organization = self::organizationOf($record);

        if ($organization === null) {
            return [];
        }

        return array_filter(
            app(Overdrafts::class)->positionsFor($organization),
            fn (OverdraftPosition $position) => $position->isOutstanding(),
        );
    }

    /** The organization behind the row: itself on its own page, or named by an overdraft row. */
    private static function organizationOf(mixed $record): ?Organization
    {
        if ($record instanceof Organization) {
            return $record;
        }

        if (is_array($record) && isset($record['organization_id'])) {
            return Organization::withTrashed()->find($record['organization_id']);
        }

        return null;
    }
}
