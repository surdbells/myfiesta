<?php

namespace App\Filament\Resources\PayoutRequests\Tables;

use App\Enums\PlatformRole;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Services\Payouts\PayoutRequestRefused;
use App\Services\Payouts\PayoutRequests;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The payout request queue.
 *
 * Each row carries what an operator needs before sending money: what was
 * asked, what is owed now (a refund since the request lowers it), and whether
 * the destination has been verified. The rules themselves live in
 * PayoutRequests and SettlementRecorder, not here.
 */
class PayoutRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization:id,name', 'requester:id,name,email', 'decider:id,name']))
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Asked for')
                    ->state(fn (PayoutRequest $record) => $record->money()->format())
                    ->description(fn (PayoutRequest $record) => $record->note),

                TextColumn::make('owed_now')
                    ->label('Owed now')
                    ->state(fn (PayoutRequest $record) => self::owed($record)->format())
                    ->color(fn (PayoutRequest $record) => $record->isPending() && self::owed($record)->amount < $record->amount ? 'danger' : null)
                    ->description(fn (PayoutRequest $record) => $record->isPending() && self::owed($record)->amount < $record->amount
                        ? 'Less than asked — the balance has dropped since'
                        : null),

                TextColumn::make('destination')
                    ->label('Pays to')
                    ->badge()
                    ->state(fn (PayoutRequest $record) => self::destination($record)?->isVerified() ? 'Verified' : (self::destination($record) ? 'Not verified' : 'No details'))
                    ->color(fn (string $state) => $state === 'Verified' ? 'success' : 'warning')
                    ->description(fn (PayoutRequest $record) => self::destination($record)?->maskedDestination()),

                TextColumn::make('requester.name')
                    ->label('Asked by')
                    ->description(fn (PayoutRequest $record) => $record->created_at?->diffForHumans()),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'paid' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (PayoutRequest $record) => match ($record->status) {
                        'paid' => (new Money((int) $record->paid_amount, $record->currency))->format().' by '.($record->decider?->name ?? 'staff'),
                        'rejected' => $record->decision_note,
                        default => null,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Waiting',
                        'paid' => 'Paid',
                        'rejected' => 'Rejected',
                        'cancelled' => 'Withdrawn',
                    ])
                    ->default('pending'),
            ])
            ->recordActions([
                self::pay(),
                self::reject(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Send the money and record it.
     *
     * The amount starts at what was asked, or what is owed now if that is
     * less. More than is owed is an overdraft: shown as such while typing,
     * needing a reason, and refused for anyone but an administrator.
     */
    private static function pay(): Action
    {
        return Action::make('pay')
            ->label('Pay')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (PayoutRequest $record) => $record->isPending())
            ->modalHeading(fn (PayoutRequest $record) => 'Pay '.$record->organization?->name)
            ->modalDescription('Send the money first, then record it here. This writes the settlement and tells the organizer it was sent.')
            ->modalSubmitActionLabel('Record payment')
            ->fillForm(function (PayoutRequest $record): array {
                $owed = self::owed($record);
                $destination = self::destination($record);

                return [
                    'amount' => number_format(min($record->amount, max($owed->amount, 0)) / 100, 2, '.', ''),
                    'rail' => $destination?->rail,
                ];
            })
            ->schema(fn (PayoutRequest $record) => [
                Placeholder::make('summary')
                    ->label('Request')
                    ->content(fn () => 'Asked for '.$record->money()->format().' · owed now '.self::owed($record)->format()
                        .($record->note ? ' · “'.$record->note.'”' : '')),

                TextInput::make('amount')
                    ->label('Amount paid')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->step('0.01')
                    ->live(onBlur: true)
                    ->helperText(function (Get $get) use ($record) {
                        $paying = (int) round(((float) $get('amount')) * 100);
                        $owed = self::owed($record);

                        if ($paying <= $owed->amount) {
                            return 'What actually left the account, in '.$record->currency.'.';
                        }

                        $over = (new Money($paying - $owed->amount, $record->currency))->format();

                        return auth()->user()?->platform_role === PlatformRole::Admin
                            ? "Overdraft: {$over} more than they are owed. It comes out of their future sales, and needs a reason below."
                            : "That is {$over} more than they are owed. Only an administrator can pay more than is owed.";
                    }),

                Select::make('rail')
                    ->label('Paid via')
                    ->required()
                    ->options([
                        'interac' => 'Interac',
                        'bank_transfer' => 'Bank transfer',
                        'stripe' => 'Stripe',
                        'paystack' => 'Paystack',
                    ]),

                Placeholder::make('destination')
                    ->label('Payout details on file')
                    ->content(function () use ($record) {
                        $detail = self::destination($record);

                        if (! $detail) {
                            return 'None. Do not send money until the organizer has added details and they are verified.';
                        }

                        return $detail->isVerified()
                            ? '✓ Verified — '.$detail->maskedDestination()
                            : '⚠ Not verified — '.$detail->maskedDestination().'. Verify under Payout details before sending; recording without it needs a reason below.';
                    }),

                Textarea::make('note')
                    ->label('Note')
                    ->helperText('Required for an overdraft or unverified details. Shown to the organizer when the amount differs from what they asked for.')
                    ->rows(3),
            ])
            ->action(function (PayoutRequest $record, array $data) {
                try {
                    app(PayoutRequests::class)->pay(
                        $record,
                        auth()->user(),
                        new Money((int) round(((float) $data['amount']) * 100), $record->currency),
                        $data['rail'],
                        $data['note'] ?? null,
                    );
                } catch (PayoutRequestRefused|SettlementRefused $refused) {
                    Notification::make()->title('Not recorded')->body($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Payment recorded')->body('The organizer has been told.')->success()->send();
            });
    }

    private static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (PayoutRequest $record) => $record->isPending())
            ->modalHeading('Reject this payout request?')
            ->modalDescription('Nothing is paid. The organizer is emailed the reason below, and can ask again.')
            ->modalSubmitActionLabel('Reject')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason, for the organizer')
                    ->required()
                    ->rows(3)
                    ->helperText('Say what they need to do, e.g. "Your bank details could not be verified — please re-enter them."'),
            ])
            ->action(function (PayoutRequest $record, array $data) {
                try {
                    app(PayoutRequests::class)->reject($record, auth()->user(), (string) $data['reason']);
                } catch (PayoutRequestRefused $refused) {
                    Notification::make()->title('Not rejected')->body($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Request rejected')->body('The organizer has been told why.')->success()->send();
            });
    }

    private static function owed(PayoutRequest $record): Money
    {
        return app(PayoutRequests::class)->available($record->organization, $record->currency);
    }

    private static function destination(PayoutRequest $record): ?OrganizationPayoutDetail
    {
        return OrganizationPayoutDetail::query()
            ->where('organization_id', $record->organization_id)
            ->where('currency', $record->currency)
            ->first();
    }
}
