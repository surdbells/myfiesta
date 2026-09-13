<?php

namespace App\Filament\Resources\Organizations\Tables;

use App\Enums\PlatformRole;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Services\Payouts\SettlementRecorder;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->tooltip(fn (Organization $r) => $r->verified_at
                        ? 'Identity documents approved'
                        : 'Not yet verified'),

                TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->alignEnd(),

                TextColumn::make('events_count')
                    ->label('Events')
                    ->counts('events')
                    ->alignEnd(),

                /*
                 * Outstanding balance, per currency.
                 *
                 * Never a single figure. An organization running events in
                 * Toronto and Lagos has two balances, and adding CAD to NGN
                 * would produce a number that means nothing.
                 */
                TextColumn::make('balances')
                    ->label('Owed')
                    ->state(function (Organization $record): string {
                        $balances = LedgerEntry::balancesFor($record);

                        if ($balances === []) {
                            return '—';
                        }

                        return collect($balances)
                            ->reject(fn (Money $m) => $m->isZero())
                            ->map(fn (Money $m) => $m->currency.' '.number_format($m->amount / 100, 2))
                            ->implode("\n") ?: '—';
                    })
                    ->badge()
                    ->separator("\n")
                    ->color(fn (string $state) => $state === '—' ? 'gray' : 'warning'),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Filter::make('unverified')
                    ->label('Awaiting verification')
                    ->query(fn (Builder $q) => $q->whereNull('verified_at')),
            ])
            ->recordActions([
                ViewAction::make(),

                /*
                 * Record a payout.
                 *
                 * The amount is classified against the live balance rather than
                 * chosen from a list: full, partial, or overdraft is a fact
                 * about the numbers, not an opinion. Overdraft demands a note,
                 * matching the database constraint rather than duplicating a
                 * rule that could drift from it.
                 */
                Action::make('settle')
                    ->label('Record settlement')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->hasPlatformRole(
                        PlatformRole::Admin,
                        PlatformRole::Finance,
                    ) ?? false)
                    ->schema(fn (Organization $record) => [
                        Select::make('currency')
                            ->label('Currency')
                            ->required()
                            ->live()
                            ->options(function () use ($record) {
                                $balances = LedgerEntry::balancesFor($record);

                                return collect($balances)
                                    ->mapWithKeys(fn (Money $m, string $code) => [
                                        $code => $code.' — '.number_format($m->amount / 100, 2).' owed',
                                    ])
                                    ->all();
                            })
                            ->helperText('Balances are held separately per currency.'),

                        TextInput::make('amount')
                            ->label('Amount paid')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step('0.01')
                            ->helperText('What actually left the account, in major units.'),

                        Select::make('rail')
                            ->label('Paid via')
                            ->required()
                            ->live()
                            ->options([
                                'interac' => 'Interac',
                                'bank_transfer' => 'Bank transfer',
                                'stripe' => 'Stripe',
                                'paystack' => 'Paystack',
                            ]),

                        /*
                         * Whether the details this would have gone to are
                         * verified, next to the amount. Read from the clear
                         * columns only — no decryption, nothing to log.
                         */
                        Placeholder::make('destination')
                            ->label('Payout details on file')
                            ->visible(fn (Get $get) => in_array($get('rail'), ['interac', 'bank_transfer'], true) && filled($get('currency')))
                            ->content(function (Get $get) use ($record) {
                                $detail = OrganizationPayoutDetail::query()
                                    ->where('organization_id', $record->id)
                                    ->where('currency', $get('currency'))
                                    ->where('rail', $get('rail'))
                                    ->first();

                                if (! $detail) {
                                    return 'None for this rail and currency. Do not send money until the organizer has added details and they are verified.';
                                }

                                return $detail->isVerified()
                                    ? '✓ Verified — '.$detail->maskedDestination()
                                    : '⚠ Not verified — '.$detail->maskedDestination().'. Verify under Payout details before sending; recording without it needs a reason below.';
                            }),

                        Textarea::make('note')
                            ->label('Note')
                            ->helperText('Required when paying more than is owed, or to details that are not verified.')
                            ->rows(3),
                    ])
                    ->action(function (Organization $record, array $data) {
                        /*
                         * The rules live in SettlementRecorder, not here.
                         *
                         * Classifying the amount, refusing an unexplained
                         * overdraft and writing the ledger entry alongside
                         * the settlement are the most consequential rules on
                         * this platform, and inside a table definition the
                         * only way to exercise them was to drive this table.
                         */
                        try {
                            $settlement = app(SettlementRecorder::class)->record(
                                $record,
                                new Money(
                                    (int) round(((float) $data['amount']) * 100),
                                    $data['currency'],
                                ),
                                $data['rail'],
                                $data['note'] ?? null,
                                auth()->user(),
                            );
                        } catch (SettlementRefused $refused) {
                            Notification::make()
                                ->title('That could not be recorded')
                                ->body($refused->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(ucfirst($settlement->type).' settlement recorded')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
