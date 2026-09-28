<?php

namespace App\Filament\Pages;

use App\Filament\Actions\RecordRepaymentAction;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Support\Listing;
use App\Models\Organization;
use App\Services\Payouts\OverdraftPosition;
use App\Services\Payouts\Overdrafts as OverdraftBook;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Every organization that owes myFiesta money, per currency.
 *
 * Money advanced when a payout request was paid beyond the balance, and
 * shortfalls where refunds overtook sales after a payout. Both come back the
 * same way — from the organization's next sales — so both are here, oldest
 * first, with how long each has been owed and what has come back so far.
 *
 * Read from the ledger each time the page is opened; nothing here is a copy
 * that could disagree with it. The one thing a row offers is recording money
 * paid back to us outside the platform.
 */
class Overdrafts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Overdrafts';

    protected static ?string $slug = 'money/overdrafts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 30;

    private const BADGE_CACHE = 'overdrafts:outstanding-count';

    /** The people who pay organizers, and so the ones who advance money. */
    public static function canAccess(): bool
    {
        return auth()->user()?->platform_role?->canSettle() ?? false;
    }

    /**
     * How many are owed, so it is seen from anywhere in the panel.
     *
     * Every page of the panel draws the navigation, and this sums the whole
     * ledger by organization; a minute old is fresh enough for a badge. The
     * page itself always reads it anew.
     */
    public static function getNavigationBadge(): ?string
    {
        $owing = (int) Cache::remember(self::BADGE_CACHE, 60, fn () => app(OverdraftBook::class)->outstandingCount());

        return $owing > 0 ? (string) $owing : null;
    }

    /** After something in this panel changed what is owed: an advance paid, a repayment recorded. */
    public static function forgetNavigationBadge(): void
    {
        Cache::forget(self::BADGE_CACHE);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Owed to myFiesta')
                ->description('Per organization and currency, the longest owed first. Each is paid back automatically from that organization’s next sales in the same currency, '
                    .'and they cannot ask to be paid until it is. Money they send us directly is recorded as a repayment.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return Listing::defaults($table, 'overdrafts')
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->rows($search, $sortColumn, $sortDirection, (int) $page, (int) $recordsPerPage))
            ->searchPlaceholder('Organization')
            ->emptyStateHeading('Nobody owes myFiesta money')
            ->emptyStateDescription('Every organization’s balance is at or above zero in every currency.')
            ->columns([
                TextColumn::make('organization')
                    ->label('Organization')
                    ->weight('medium')
                    ->searchable()
                    ->sortable()
                    ->url(fn (array $record): string => OrganizationResource::getUrl('view', ['record' => $record['organization_id']]))
                    ->description(fn (array $record): ?string => $record['standing']),

                TextColumn::make('outstanding_text')
                    ->label('Outstanding')
                    ->weight('semibold')
                    ->color('danger')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('cause')
                    ->label('What took it below zero')
                    ->badge()
                    ->color(fn (array $record): string => $record['advanced_text'] !== null ? 'warning' : 'gray')
                    ->description(fn (array $record): ?string => $record['approved']),

                TextColumn::make('age')
                    ->label('Owed for')
                    ->sortable()
                    ->description(fn (array $record): ?string => $record['since_text']),

                TextColumn::make('recovered_text')
                    ->label('Recovered from sales')
                    ->alignEnd(),

                TextColumn::make('repaid_text')
                    ->label('Repaid')
                    ->alignEnd(),

                TextColumn::make('summary')
                    ->label('In full')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reason')
                    ->label('Why it was advanced')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                RecordRepaymentAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * One page of rows, searched and sorted here: the list is worked out
     * from balances, not queried from a table of its own.
     */
    private function rows(?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $perPage): LengthAwarePaginator
    {
        $positions = app(OverdraftBook::class)->outstanding();

        $names = Organization::withTrashed()
            ->whereIn('id', array_map(fn (OverdraftPosition $p) => $p->organizationId, $positions))
            ->get(['id', 'name', 'suspended_at', 'deleted_at'])
            ->keyBy('id');

        $rows = collect($positions)
            ->map(fn (OverdraftPosition $position) => $this->row($position, $names->get($position->organizationId)))
            ->when(filled($search), fn ($rows) => $rows->filter(
                fn (array $row) => str_contains(mb_strtolower($row['organization']), mb_strtolower((string) $search)),
            ));

        $descending = $sortDirection === 'desc';

        $rows = match ($sortColumn) {
            'organization' => $rows->sortBy('organization', SORT_NATURAL | SORT_FLAG_CASE, $descending),
            // Within each currency: amounts in two currencies are never compared.
            'outstanding_text' => $rows->sortBy([['currency', 'asc'], ['outstanding', $descending ? 'desc' : 'asc']]),
            'age' => $rows->sortBy('since_timestamp', SORT_REGULAR, ! $descending),
            default => $rows,
        };

        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
        );
    }

    /** @return array<string, mixed> */
    private function row(OverdraftPosition $position, ?Organization $organization): array
    {
        $since = $position->since;
        $days = $since ? (int) floor($since->diffInDays(CarbonImmutable::now(), absolute: true)) : null;

        return [
            // One row per organization and currency, and the row's action
            // finds it again by this.
            ArrayRecord::getKeyName() => $position->organizationId.':'.$position->currency,
            'organization_id' => $position->organizationId,
            'organization' => $organization->name ?? 'A removed organization',
            'standing' => match (true) {
                $organization?->trashed() => 'Closed',
                $organization?->isSuspended() => 'Suspended — payouts frozen',
                default => null,
            },
            'currency' => $position->currency,
            'outstanding' => $position->outstanding->amount,
            'outstanding_text' => $position->outstanding->format(),
            'cause' => match (true) {
                $position->isAdvance() => 'Advance of '.$position->advanced->format(),
                $position->since !== null => 'Refunds after a payout',
                default => 'No advance on record',
            },
            'advanced_text' => $position->isAdvance() ? $position->advanced->format() : null,
            'approved' => $position->decidedBy(),
            'reason' => $position->reason(),
            'age' => match (true) {
                $days === null => '—',
                $days === 0 => 'Since today',
                $days === 1 => '1 day',
                default => number_format($days).' days',
            },
            'since_text' => $since ? 'Since '.$since->format('j M Y').' (UTC)' : null,
            'since_timestamp' => $since?->getTimestamp() ?? 0,
            'recovered_text' => $position->recovered->format(),
            'repaid_text' => $position->repaid->format(),
            'summary' => $position->summary(),
        ];
    }
}
