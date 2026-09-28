<?php

namespace App\Filament\Resources\Organizations\Tables;

use App\Enums\PlatformRole;
use App\Filament\Actions\ImpersonateOrganizationAction;
use App\Filament\Support\Listing;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Services\Discovery\EventWindows;
use App\Services\Payouts\OwnOrganization;
use App\Services\Payouts\SettlementRecorder;
use App\Services\Payouts\SettlementRefused;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every organization, found by whatever support was given.
 *
 * A name, the slug from a link, or an owner's email address — which is what
 * arrives in the inbox, since the person writing in rarely knows what their
 * organization is called on the platform. Counts come from the one query
 * (members, events, events on sale), and an organization has no country or
 * currency of its own: the filters for those ask its events.
 */
class OrganizationsTable
{
    /** Where the platform sells. An organization is in a market by having an event there. */
    public const COUNTRIES = ['CA' => 'Canada', 'NG' => 'Nigeria'];

    public const VERIFICATION = [
        'verified' => 'Verified',
        'renamed' => 'Renamed since verification',
        'documents_pending' => 'Documents waiting for review',
        'unverified' => 'Not verified',
    ];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'organizations')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount([
                    'members',
                    'events',
                    // Not a night that is over: it stays published, among
                    // their past events, and sells nothing.
                    'events as on_sale_count' => fn (Builder $events) => EventWindows::onSale($events, now()),
                ])
                ->addSelect([
                    // The first owner, which is who support usually needs to
                    // reach. The page lists every one of them.
                    'owner_email' => DB::table('organization_user')
                        ->join('users', 'users.id', '=', 'organization_user.user_id')
                        ->whereColumn('organization_user.organization_id', 'organizations.id')
                        ->where('organization_user.role', 'owner')
                        ->orderBy('organization_user.created_at')
                        ->limit(1)
                        ->select('users.email'),
                    'sells_in' => DB::table('events')
                        ->whereColumn('events.organization_id', 'organizations.id')
                        ->whereNull('events.deleted_at')
                        ->selectRaw("string_agg(distinct events.currency, ' · ' order by events.currency)"),
                ]))
            ->searchPlaceholder('Name, link or owner’s email')
            ->columns([
                TextColumn::make('name')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search))
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Organization $record) => '/o/'.$record->slug)
                    ->wrap(),

                TextColumn::make('owner_email')
                    ->label('Owner')
                    ->placeholder('No owner')
                    ->copyable()
                    ->toggleable(),

                /*
                 * Whether the platform is selling for it. Blank for the
                 * ordinary case, so the ones that are not stand out.
                 */
                TextColumn::make('suspended_at')
                    ->label('Standing')
                    ->badge()
                    ->formatStateUsing(fn () => 'Suspended')
                    ->color('danger')
                    ->description(fn (Organization $record) => $record->suspended_at?->diffForHumans())
                    ->placeholder('Active')
                    ->sortable(),

                /*
                 * Three states, not two. An organization that renamed itself
                 * after being verified keeps its approved documents and loses
                 * the public tick until somebody agrees the new name is still
                 * them — so "verified" and "verified, but called something
                 * else now" cannot look the same here.
                 */
                IconColumn::make('verified_at')
                    ->label('Verified')
                    ->icon(fn (Organization $r) => match (true) {
                        $r->isVerified() => 'heroicon-o-check-circle',
                        $r->awaitsRenameCheck() => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-x-circle',
                    })
                    ->color(fn (Organization $r) => match (true) {
                        $r->isVerified() => 'success',
                        $r->awaitsRenameCheck() => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Organization $r) => match (true) {
                        $r->isVerified() => 'Identity documents approved',
                        $r->awaitsRenameCheck() => 'Renamed since verification, from "'.$r->verified_name.'". The tick is hidden until this is confirmed.',
                        default => 'Not yet verified',
                    })
                    ->sortable(),

                TextColumn::make('members_count')
                    ->label('Members')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('events_count')
                    ->label('Events')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('on_sale_count')
                    ->label('On sale')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('sells_in')
                    ->label('Sells in')
                    ->placeholder('—')
                    ->toggleable(),

                /*
                 * Outstanding balance, per currency.
                 *
                 * Never a single figure. An organization running events in
                 * Toronto and Lagos has two balances, and adding CAD to NGN
                 * would produce a number that means nothing. Each is written
                 * as the organizer's console writes it — "$190.00", "₦5,000"
                 * — so the figure read out on a call is the one on screen.
                 * One below zero is money the organization owes myFiesta (see
                 * Overdrafts), and says so rather than wearing a minus sign.
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
                            ->map(fn (Money $m) => $m->isNegative()
                                ? 'Owes myFiesta '.(new Money(-$m->amount, $m->currency))->format()
                                : $m->format())
                            ->implode("\n") ?: '—';
                    })
                    ->badge()
                    ->separator("\n")
                    ->color(fn (string $state) => match (true) {
                        $state === '—' => 'gray',
                        str_starts_with($state, 'Owes myFiesta') => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (Organization $record) => $record->isSuspended() ? 'Payouts frozen' : null),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('country')
                    ->label('Country')
                    ->options(self::COUNTRIES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('events', fn (Builder $events) => $events->where('country', $data['value']))
                        : $query),

                SelectFilter::make('currency')
                    ->label('Currency')
                    ->options(Listing::CURRENCIES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('events', fn (Builder $events) => $events->where('currency', $data['value']))
                        : $query),

                SelectFilter::make('verification')
                    ->label('Verification')
                    ->options(self::VERIFICATION)
                    ->query(fn (Builder $query, array $data): Builder => self::verification($query, $data['value'] ?? null)),

                TernaryFilter::make('suspended')
                    ->label('Suspended')
                    ->trueLabel('Suspended')
                    ->falseLabel('Not suspended')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('organizations.suspended_at'),
                        false: fn (Builder $query) => $query->whereNull('organizations.suspended_at'),
                    ),

                TernaryFilter::make('on_sale')
                    ->label('Events on sale')
                    ->trueLabel('Has events on sale')
                    ->falseLabel('Nothing on sale')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('events', fn (Builder $events) => EventWindows::onSale($events, now())),
                        false: fn (Builder $query) => $query->whereDoesntHave('events', fn (Builder $events) => EventWindows::onSale($events, now())),
                    ),

                Listing::dateRange('joined', 'created_at', 'Joined'),
            ])
            ->recordActions([
                ViewAction::make(),

                // Staff opening the organization's console as it; see the class.
                ImpersonateOrganizationAction::make(),

                /*
                 * Agreeing that a renamed organization is still the one whose
                 * documents were approved.
                 *
                 * A glance rather than a re-upload: making an organizer send a
                 * passport again because they fixed a typo in their own name
                 * is how a verification queue fills with work nobody needed.
                 */
                Action::make('confirmName')
                    ->label('Confirm new name')
                    ->icon('heroicon-o-check-badge')
                    ->color('warning')
                    ->visible(fn (Organization $record) => $record->awaitsRenameCheck()
                        && (auth()->user()?->hasPlatformRole(PlatformRole::Admin, PlatformRole::Support) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Organization $record) => 'Confirm '.$record->name.' as the organization that was verified?')
                    ->modalDescription(fn (Organization $record) => 'Verified as "'.$record->verified_name.'", now called "'.$record->name.'". Confirming shows the tick again under the new name, on every page.')
                    ->modalSubmitActionLabel('Confirm the new name')
                    ->action(function (Organization $record) {
                        $record->update(['verified_name' => $record->name]);

                        Notification::make()->title('Name confirmed')->success()->send();
                    }),

                /*
                 * Record a payout.
                 *
                 * The amount is classified against the live balance rather than
                 * chosen from a list: full, partial, or overdraft is a fact
                 * about the numbers, not an opinion. Paying more than is owed
                 * is refused here: an overdraft is only given by staff paying
                 * an organizer's payout request, with a reason, and kept on
                 * that request (PayoutRequests::pay).
                 *
                 * Hidden while the organization is suspended, when its payouts
                 * are frozen; SettlementRecorder refuses it as well.
                 */
                Action::make('settle')
                    ->label('Record settlement')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Organization $record) => ! $record->isSuspended() && (auth()->user()?->hasPlatformRole(
                        PlatformRole::Admin,
                        PlatformRole::Finance,
                    ) ?? false))
                    ->disabled(fn (Organization $record) => OwnOrganization::includesCurrentUser($record))
                    ->tooltip(fn (Organization $record) => OwnOrganization::includesCurrentUser($record) ? OwnOrganization::RECORD_PAYOUT : null)
                    ->modalHeading(fn (Organization $record) => 'Record a settlement to '.$record->name.'?')
                    ->modalDescription('For money that has already left myFiesta’s account. It is written to the ledger and lowers what they are owed in that currency, and it cannot be taken back from here. Recorded under your name.')
                    ->modalSubmitActionLabel('Record settlement')
                    ->schema(fn (Organization $record) => [
                        Select::make('currency')
                            ->label('Currency')
                            ->required()
                            ->live()
                            ->options(function () use ($record) {
                                $balances = LedgerEntry::balancesFor($record);

                                // A balance below zero has nothing to pay
                                // from; it is still listed, saying so.
                                return collect($balances)
                                    ->mapWithKeys(fn (Money $m, string $code) => [
                                        $code => $m->isNegative()
                                            ? $code.' — they owe myFiesta '.(new Money(-$m->amount, $m->currency))->format()
                                            : $code.' — '.$m->format().' owed',
                                    ])
                                    ->all();
                            })
                            ->helperText('Balances are held separately per currency.'),

                        // Typed in dollars or naira, as it left the bank; the
                        // symbol appears once the currency is chosen.
                        TextInput::make('amount')
                            ->label('Amount paid')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step('0.01')
                            ->prefix(fn (Get $get) => Listing::prefix($get('currency')))
                            ->helperText(fn (Get $get) => 'What actually left the account, in '
                                .(filled($get('currency')) ? Listing::unitName($get('currency')) : 'dollars or naira')
                                .'. Up to what is owed — to pay more, pay the organizer’s payout request.'),

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
                            ->helperText('Required when paying to details that are not verified.')
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

    /** By name, the slug in a link, the contact address, or any owner's email. */
    private static function search(Builder $query, string $search): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($search)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('organizations.name', 'ilike', $like)
            ->orWhere('organizations.slug', 'ilike', $like)
            ->orWhere('organizations.contact_email', 'ilike', $like)
            ->orWhereIn('organizations.id', DB::table('organization_user')
                ->join('users', 'users.id', '=', 'organization_user.user_id')
                ->where('organization_user.role', 'owner')
                ->where('users.email', 'ilike', $like)
                ->select('organization_user.organization_id')));
    }

    /** The states the page's Verification badge shows, as queries. */
    private static function verification(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            'verified' => $query->whereNotNull('organizations.verified_at')->whereColumn('organizations.verified_name', 'organizations.name'),
            'renamed' => $query->whereNotNull('organizations.verified_at')->where(fn (Builder $q) => $q
                ->whereNull('organizations.verified_name')
                ->orWhereColumn('organizations.verified_name', '!=', 'organizations.name')),
            'documents_pending' => $query->whereNull('organizations.verified_at')
                ->whereHas('identityDocuments', fn (Builder $documents) => $documents->where('review_status', 'pending')),
            'unverified' => $query->whereNull('organizations.verified_at')
                ->whereDoesntHave('identityDocuments', fn (Builder $documents) => $documents->where('review_status', 'pending')),
            default => $query,
        };
    }
}
