<?php

namespace App\Filament\Resources\PayoutRequests\Tables;

use App\Filament\Pages\Overdrafts as OverdraftsPage;
use App\Filament\Support\Listing;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Services\Payouts\OwnOrganization;
use App\Services\Payouts\PayoutRequestRefused;
use App\Services\Payouts\PayoutRequests;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use WeakMap;

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
    public const STATUSES = [
        'pending' => 'Waiting',
        'paid' => 'Paid',
        'rejected' => 'Rejected',
        'cancelled' => 'Withdrawn',
    ];

    /** @var WeakMap<PayoutRequest, Money>|null */
    private static ?WeakMap $owed = null;

    /** @var WeakMap<PayoutRequest, array{detail: OrganizationPayoutDetail|null}>|null */
    private static ?WeakMap $destinations = null;

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'payout requests')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization:id,name', 'requester:id,name,email', 'decider:id,name']))
            ->searchPlaceholder('Organization, who asked, or their note')
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Listing::money('amount', 'Asked for')
                    ->description(fn (PayoutRequest $record) => $record->note)
                    ->searchable(['note'])
                    // Waiting and paid only: a request withdrawn or refused
                    // was never money anybody was going to send.
                    ->summarize(Listing::totalsPerCurrency('amount', 'Waiting or paid', only: fn (QueryBuilder $query) => $query->whereIn('status', ['pending', 'paid']))),

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
                    ->searchable(['name', 'email'])
                    ->description(fn (PayoutRequest $record) => $record->requester?->email)
                    ->placeholder('A former member'),

                TextColumn::make('created_at')
                    ->label('Asked')
                    ->since()
                    ->dateTimeTooltip('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state, PayoutRequest $record) => match (true) {
                        $state === 'pending' && $record->held_at !== null => 'danger',
                        $state === 'pending' => 'warning',
                        $state === 'paid' => 'success',
                        $state === 'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (PayoutRequest $record) => match (true) {
                        $record->status === 'paid' => Listing::format((int) $record->paid_amount, $record->currency).' by '.($record->decider?->name ?? 'staff')
                            .($record->overdraft_amount !== null ? ' · '.Listing::format($record->overdraft_amount, $record->currency).' advanced' : ''),
                        $record->status === 'rejected' => $record->decision_note,
                        // Waiting, but not to be paid: see Suspension.
                        $record->isPending() && $record->held_at !== null => 'Held — the organization is suspended',
                        default => null,
                    })
                    ->sortable(),

                TextColumn::make('decided_at')
                    ->label('Decided')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(self::STATUSES)
                    ->default('pending'),

                Listing::currency(),

                SelectFilter::make('organization')
                    ->relationship('organization', 'name', fn (Builder $query) => $query->withTrashed())
                    ->searchable(),

                // Whether there is a checked destination to send this to. The
                // same match the pay form makes: this organization, this
                // currency.
                TernaryFilter::make('destination_verified')
                    ->label('Payout details')
                    ->trueLabel('Verified details on file')
                    ->falseLabel('Unverified, or none')
                    ->queries(
                        true: fn (Builder $query) => $query->whereExists(self::verifiedDestination()),
                        false: fn (Builder $query) => $query->whereNotExists(self::verifiedDestination()),
                    ),

                Listing::dateRange('asked', 'created_at', 'Asked'),

                // Paid beyond the balance: the decisions somebody is asked
                // about later, and the reason is on each.
                TernaryFilter::make('advanced')
                    ->label('Advances')
                    ->trueLabel('Paid with an advance')
                    ->falseLabel('Paid from the balance, or not paid')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('payout_requests.overdraft_amount'),
                        false: fn (Builder $query) => $query->whereNull('payout_requests.overdraft_amount'),
                    ),

                // Waiting on an organization's suspension rather than on us.
                TernaryFilter::make('held')
                    ->label('Held for a suspension')
                    ->trueLabel('Held')
                    ->falseLabel('Not held')
                    ->queries(
                        true: fn (Builder $query) => $query->where('payout_requests.status', 'pending')->whereNotNull('payout_requests.held_at'),
                        false: fn (Builder $query) => $query->whereNull('payout_requests.held_at'),
                    ),
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
     * and then asked about on its own — what they are owed, what is being
     * paid, the difference, and why — before anything is recorded.
     */
    private static function pay(): Action
    {
        return Action::make('pay')
            ->label('Pay')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            // Not while held: the organization is suspended and its payouts
            // are frozen. PayoutRequests refuses it as well.
            ->visible(fn (PayoutRequest $record) => $record->isPending() && $record->held_at === null)
            // Not by somebody on the organization's own team: said on the
            // button rather than after the form. PayoutRequests refuses too.
            ->disabled(fn (PayoutRequest $record) => OwnOrganization::includesCurrentUser($record->organization_id))
            ->tooltip(fn (PayoutRequest $record) => OwnOrganization::includesCurrentUser($record->organization_id) ? OwnOrganization::DECIDE_REQUEST : null)
            ->modalHeading(fn (PayoutRequest $record) => 'Pay '.($record->organization->name ?? 'this organization').'’s request for '.Listing::format((int) $record->amount, $record->currency).'?')
            ->modalDescription('Send the money first, then record it here. This writes the settlement and tells the organizer it was sent.')
            ->modalSubmitActionLabel('Record payment')
            ->registerModalActions([self::advance()])
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
                    // The symbol the request above is written in, not its
                    // code: "$" beside "Asked for $50.00", never "CAD".
                    ->prefix(Listing::prefix($record->currency))
                    ->helperText(function (Get $get) use ($record) {
                        $paying = (int) round(((float) $get('amount')) * 100);
                        $owed = self::owed($record);

                        if ($paying <= $owed->amount) {
                            return 'What actually left the account, in '.Listing::unitName($record->currency).'.';
                        }

                        $over = (new Money($paying - $owed->amount, $record->currency))->format();

                        return "Overdraft: {$over} more than they are owed. You will be asked to confirm the advance and say why. It comes out of their next sales.";
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
                    ->helperText('Required for unverified details. Shown to the organizer when the amount differs from what they asked for.')
                    ->rows(3),
            ])
            ->action(function (PayoutRequest $record, array $data, HasActions&Component $livewire) {
                $amount = new Money((int) round(((float) $data['amount']) * 100), $record->currency);

                // Read again rather than from the row: the balance may have
                // moved while the form was open.
                $owed = app(PayoutRequests::class)->available($record->organization, $record->currency);

                // More than is owed: nothing is recorded yet. The advance is
                // asked about on its own, over this form, with the figures.
                if ($amount->amount > $owed->amount) {
                    $livewire->mountAction('advance', [
                        'amount' => $amount->amount,
                        'rail' => $data['rail'],
                        'note' => $data['note'] ?? null,
                    ], ['table' => true, 'recordKey' => $record->getKey()]);

                    return;
                }

                self::record($livewire, $record, $amount, (string) $data['rail'], $data['note'] ?? null);
            });
    }

    /**
     * Paying more than is owed, confirmed.
     *
     * Opened by Pay when the amount is more than the balance. Everything the
     * decision rests on is in front of the person making it — what the
     * organization is owed now, what they asked for, what is being paid and
     * the difference myFiesta is advancing — and nothing is recorded until
     * they say why. The reason is kept on the request, in the audit trail and
     * under the payment in the organizer's statement.
     */
    private static function advance(): Action
    {
        $figures = function (PayoutRequest $record, array $arguments): array {
            $owed = app(PayoutRequests::class)->available($record->organization, $record->currency);
            $paying = new Money((int) ($arguments['amount'] ?? 0), $record->currency);

            return [$owed, $paying, new Money(max(0, $paying->amount - $owed->amount), $record->currency)];
        };

        return Action::make('advance')
            ->color('warning')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalHeading(function (PayoutRequest $record, array $arguments) use ($figures) {
                [, , $over] = $figures($record, $arguments);

                return 'Advance '.$over->format().' to '.$record->organization?->name.'?';
            })
            ->modalDescription(function (PayoutRequest $record, array $arguments) use ($figures) {
                [, , $over] = $figures($record, $arguments);

                // Paying the balance and the advance leaves exactly the
                // advance below zero: that is the whole of the ledger's side.
                return 'This pays more than they are owed. The difference is an advance from myFiesta: their '
                    .$record->currency.' balance goes to '.(new Money(-$over->amount, $record->currency))->format()
                    .'. Their next sales pay it back before anything else is paid out, and they cannot ask to be paid again until the balance is above zero. '
                    .'It is recorded with your name, the time and your reason.';
            })
            ->schema(fn (PayoutRequest $record, array $arguments) => [
                Placeholder::make('owed')
                    ->label('Owed to them now')
                    ->content(fn () => $figures($record, $arguments)[0]->format()),
                Placeholder::make('asked')
                    ->label('Asked for')
                    ->content($record->money()->format()),
                Placeholder::make('paying')
                    ->label('Paying')
                    ->content(fn () => $figures($record, $arguments)[1]->format()),
                Placeholder::make('overdraft')
                    ->label('Overdraft — advanced by myFiesta')
                    ->content(fn () => $figures($record, $arguments)[2]->format()),
                Textarea::make('reason')
                    ->label('Why myFiesta is advancing this')
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000)
                    ->rows(3)
                    ->helperText('Kept on the request and in the audit trail, and shown under the payment in their statement.'),
            ])
            ->modalSubmitActionLabel(function (PayoutRequest $record, array $arguments) use ($figures) {
                [, , $over] = $figures($record, $arguments);

                return 'Advance '.$over->format().' and record payment';
            })
            // Done or refused, the pay form underneath has nothing left to do.
            ->cancelParentActions()
            ->action(function (PayoutRequest $record, array $data, array $arguments, Component $livewire) {
                self::record(
                    $livewire,
                    $record,
                    new Money((int) ($arguments['amount'] ?? 0), $record->currency),
                    (string) ($arguments['rail'] ?? ''),
                    $arguments['note'] ?? null,
                    (string) $data['reason'],
                );
            });
    }

    /** Pay it, and say how that went. */
    private static function record(Component $livewire, PayoutRequest $record, Money $amount, string $rail, ?string $note, ?string $overdraftReason = null): void
    {
        try {
            $paid = app(PayoutRequests::class)->pay($record, auth()->user(), $amount, $rail, $note, $overdraftReason);
        } catch (PayoutRequestRefused|SettlementRefused $refused) {
            Notification::make()->title('Not recorded')->body($refused->getMessage())->danger()->send();

            return;
        }

        $advance = $paid->overdraft();

        if ($advance) {
            OverdraftsPage::forgetNavigationBadge();
        }

        // The counts beside Payout requests and Overdrafts in the navigation,
        // which is drawn outside this table: one fewer waiting, and one more
        // owing after an advance. As a repayment and a suspension do.
        $livewire->dispatch('refresh-sidebar');

        Notification::make()
            ->title('Payment recorded')
            ->body($advance
                ? 'Including an advance of '.$advance->format().', recovered from their next sales. The organizer has been told.'
                : 'The organizer has been told.')
            ->success()
            ->send();
    }

    private static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (PayoutRequest $record) => $record->isPending())
            ->disabled(fn (PayoutRequest $record) => OwnOrganization::includesCurrentUser($record->organization_id))
            ->tooltip(fn (PayoutRequest $record) => OwnOrganization::includesCurrentUser($record->organization_id) ? OwnOrganization::DECIDE_REQUEST : null)
            ->modalHeading(fn (PayoutRequest $record) => 'Reject '.($record->organization->name ?? 'this organization').'’s request for '.Listing::format((int) $record->amount, $record->currency).'?')
            ->modalDescription('Nothing is paid. The organizer is emailed the reason below, and can ask again.')
            ->modalSubmitActionLabel('Reject the request')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason, for the organizer')
                    ->required()
                    ->rows(3)
                    ->helperText('Say what they need to do, e.g. "Your bank details could not be verified — please re-enter them."'),
            ])
            ->action(function (PayoutRequest $record, array $data, Component $livewire) {
                try {
                    app(PayoutRequests::class)->reject($record, auth()->user(), (string) $data['reason']);
                } catch (PayoutRequestRefused $refused) {
                    Notification::make()->title('Not rejected')->body($refused->getMessage())->danger()->send();

                    return;
                }

                // One fewer waiting beside Payout requests in the navigation.
                $livewire->dispatch('refresh-sidebar');

                Notification::make()->title('Request rejected')->body('The organizer has been told why.')->success()->send();
            });
    }

    /**
     * What the organization is owed in this currency, read once per row.
     *
     * Three columns and the pay form ask; each asking is a ledger sum. Kept
     * against the row object, so a page is fresh when it is loaded again —
     * after a payment the table reads its rows anew.
     */
    private static function owed(PayoutRequest $record): Money
    {
        self::$owed ??= new WeakMap;

        return self::$owed[$record] ??= app(PayoutRequests::class)->available($record->organization, $record->currency);
    }

    private static function destination(PayoutRequest $record): ?OrganizationPayoutDetail
    {
        self::$destinations ??= new WeakMap;

        if (! isset(self::$destinations[$record])) {
            self::$destinations[$record] = ['detail' => OrganizationPayoutDetail::query()
                ->where('organization_id', $record->organization_id)
                ->where('currency', $record->currency)
                ->orderByRaw('verified_at is null')
                ->first()];
        }

        return self::$destinations[$record]['detail'];
    }

    /** A verified destination for this request's organization and currency. */
    private static function verifiedDestination(): Closure
    {
        return fn ($details) => $details
            ->select(DB::raw(1))
            ->from('organization_payout_details')
            ->whereColumn('organization_payout_details.organization_id', 'payout_requests.organization_id')
            ->whereColumn('organization_payout_details.currency', 'payout_requests.currency')
            ->whereNotNull('organization_payout_details.verified_at');
    }
}
