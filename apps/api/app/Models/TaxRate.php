<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Keyed on jurisdiction, not currency.
 *
 * Currency looks like a workable key because CAD implies Canada, and it is
 * wrong for most of Canada: rates vary by province. Nigerian VAT being flat is
 * what makes the shortcut appear to work.
 */
class TaxRate extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inclusive' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * The rate in force for a jurisdiction on a given date.
     *
     * Falls back from province to country, so Ontario resolves to HST while a
     * province without its own row still gets the federal rate.
     */
    public static function resolve(string $country, ?string $subdivision, ?string $on = null): ?self
    {
        $on ??= now()->toDateString();

        return static::query()
            ->where('country', $country)
            ->where('effective_from', '<=', $on)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $on))
            ->where(fn (Builder $q) => $q->where('subdivision', $subdivision)->orWhereNull('subdivision'))
            // A subdivision-specific row wins over the country-wide fallback.
            ->orderByRaw('subdivision IS NULL')
            ->first();
    }
}
