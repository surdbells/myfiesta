<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * The pieces every admin listing shares.
 *
 * One place for page sizes, date ranges and money, so thirty tables do not
 * each decide them slightly differently — and so the rule that money is never
 * added across currencies is written once, here, rather than remembered at
 * each column.
 */
final class Listing
{
    public const CURRENCIES = ['CAD' => 'CAD — Canadian dollar', 'NGN' => 'NGN — Nigerian naira'];

    /** Page sizes, a sensible default, and filters that survive a page reload. */
    public static function defaults(Table $table, string $noun = 'records'): Table
    {
        return $table
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersFormColumns(2)
            ->emptyStateHeading('No '.$noun.' match')
            ->emptyStateDescription('Nothing here yet, or nothing matches the search and filters. Clear them to see everything.');
    }

    /**
     * From and until, on one timestamp column.
     *
     * Whole days in UTC, both ends inclusive: "until the 3rd" includes the
     * evening of the 3rd, which is what anybody typing it means.
     */
    public static function dateRange(string $name, string $column, string $label): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->schema([
                DatePicker::make('from')->label($label.' from'),
                DatePicker::make('until')->label($label.' until'),
            ])
            ->columns(2)
            ->columnSpan(2)
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $qualified = $query->getModel()->qualifyColumn($column);

                return $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where($qualified, '>=', Carbon::parse($date)->startOfDay()))
                    ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where($qualified, '<=', Carbon::parse($date)->endOfDay()));
            })
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if ($data['from'] ?? null) {
                    $indicators[] = Indicator::make($label.' from '.Carbon::parse($data['from'])->format('j M Y'))->removeField('from');
                }

                if ($data['until'] ?? null) {
                    $indicators[] = Indicator::make($label.' until '.Carbon::parse($data['until'])->format('j M Y'))->removeField('until');
                }

                return $indicators;
            });
    }

    public static function currency(string $column = 'currency'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Currency')
            ->options(self::CURRENCIES);
    }

    /**
     * An amount in minor units beside the currency it is in.
     *
     * Never a bare number: "12,500.00" means one thing in Toronto and another
     * in Lagos, and the column cannot know which the reader assumes.
     */
    public static function money(string $name, string $label, string $currencyColumn = 'currency'): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable()
            ->formatStateUsing(fn ($state, $record) => $state === null
                ? '—'
                : self::format((int) $state, (string) data_get($record, $currencyColumn)));
    }

    /**
     * The total of a money column, one figure per currency.
     *
     * Over everything the filters currently match, not just the page on
     * screen. Filter to one currency to sort by amount meaningfully; the
     * totals are split whether or not you do.
     */
    public static function totalsPerCurrency(string $column, string $label = 'Total', string $currencyColumn = 'currency'): Summarizer
    {
        return Summarizer::make()
            ->label($label)
            ->using(function (QueryBuilder $query) use ($column, $currencyColumn): string {
                $totals = $query
                    ->selectRaw("{$currencyColumn} as summary_currency, coalesce(sum({$column}), 0) as summary_total")
                    ->groupBy($currencyColumn)
                    ->orderBy($currencyColumn)
                    ->get();

                if ($totals->isEmpty()) {
                    return '—';
                }

                return $totals
                    ->map(fn ($row) => self::format((int) $row->summary_total, (string) $row->summary_currency))
                    ->implode(' · ');
            });
    }

    /**
     * An amount as the rest of the product writes it: "$1,250.00", "₦5,000".
     *
     * Money::format(), which the apps and the emails also use — an organizer
     * on the phone reads their console's figure out loud, and support should
     * be looking at the same string, not "CAD 1,250.00".
     */
    public static function format(int $minorUnits, ?string $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return number_format($minorUnits / 100, 2).' (currency unknown)';
        }

        return (new Money($minorUnits, $currency))->format();
    }

    /**
     * "$", "₦" — the prefix on a field an amount is typed into, or none until
     * the form knows the currency.
     *
     * The symbol the figure will be read back with, so the field and the
     * balance above it name the currency the same way.
     */
    public static function prefix(?string $currency): ?string
    {
        $currency = strtoupper(trim((string) $currency));

        return preg_match('/^[A-Z]{3}$/', $currency) ? trim(Money::symbol($currency)) : null;
    }

    /**
     * "dollars", "naira" — what an amount field is typed in, for the words
     * under it.
     *
     * Staff type what left the bank, which is dollars and cents or naira and
     * kobo, never the minor units the ledger keeps.
     */
    public static function unitName(?string $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        return match ($currency) {
            'CAD' => 'dollars',
            'NGN' => 'naira',
            default => $currency !== '' ? $currency : 'the currency paid in',
        };
    }
}
