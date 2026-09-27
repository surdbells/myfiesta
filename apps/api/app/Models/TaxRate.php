<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

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

    /**
     * What a rate is, as opposed to what it is called.
     *
     * Fixed once the rate has applied to anything. Changing the percentage of
     * a rate in force would re-price, on paper, every order that points at
     * it; changing its place or its start date would move those orders onto
     * a rate they were never charged. A rate changes by being superseded
     * (TaxRateChanges): the old row is closed on a date and a new one takes
     * over. The name may still be corrected, and the closing date is how
     * superseding works.
     */
    public const TERMS = ['country', 'subdivision', 'rate_bps', 'inclusive', 'effective_from'];

    protected static function booted(): void
    {
        static::updating(function (self $rate) {
            if ($rate->isDirty(self::TERMS) && $rate->hasApplied()) {
                throw new LogicException(
                    "The {$rate->name} rate has already applied to sales, so it cannot be changed. Supersede it with a new rate instead."
                );
            }

            // A rate nothing has used yet starts in the future, and may be
            // moved — but not into the past. Orders placed on those days were
            // charged something else, the same reason TaxRateChanges never
            // backdates a supersession.
            if ($rate->isDirty('effective_from') && $rate->effective_from->lt(today())) {
                throw new LogicException(
                    "The {$rate->name} rate cannot be moved to start before today. Orders already placed keep the rate they were charged."
                );
            }
        });
    }

    /**
     * Kept upper case, the way events name their province.
     *
     * resolve() matches the event's province exactly, so an Ontario rate
     * typed as "on" would never apply to an Ontario event, which would
     * quietly fall back to the federal rate.
     */
    protected function subdivision(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => filled($value) ? strtoupper(trim($value)) : null,
        );
    }

    /**
     * The rows for a place whose dates share at least one day with
     * [from, to). No end date means open-ended.
     *
     * A place has one rate on any day, or checkout would be choosing between
     * two. The database refuses an overlap outright (tax_rates_no_overlap);
     * this is how the admin finds it first and says which rate is in the way.
     */
    public static function overlapping(string $country, ?string $subdivision, CarbonInterface|string $from, CarbonInterface|string|null $to): Builder
    {
        $from = Carbon::parse($from)->toDateString();
        $to = $to === null ? null : Carbon::parse($to)->toDateString();

        return static::query()
            ->forPlace($country, $subdivision)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('effective_from', '<', $to));
    }

    /** One place exactly: a province, or the country-wide row. */
    public function scopeForPlace(Builder $query, string $country, ?string $subdivision): void
    {
        $query->where('country', strtoupper($country))
            ->where(fn (Builder $q) => filled($subdivision)
                ? $q->whereRaw('upper(subdivision) = ?', [strtoupper(trim($subdivision))])
                : $q->whereNull('subdivision'));
    }

    /**
     * The rate this one took over from: the same place, closed on the day
     * this one starts. What a supersession leaves behind.
     *
     * Judged on this row as saved, so it still answers while the form is
     * moving the start date.
     */
    public function replaces(): ?self
    {
        $from = $this->getRawOriginal('effective_from');

        if (! $this->exists || $from === null) {
            return null;
        }

        return static::query()
            ->forPlace($this->getRawOriginal('country'), $this->getRawOriginal('subdivision'))
            ->whereKeyNot($this->getKey())
            ->whereDate('effective_to', Carbon::parse($from)->toDateString())
            ->first();
    }

    /** Whether another rate takes over from this one on the day it closes. */
    public function isReplaced(): bool
    {
        $to = $this->getRawOriginal('effective_to');

        return $this->exists && $to !== null && static::query()
            ->forPlace($this->getRawOriginal('country'), $this->getRawOriginal('subdivision'))
            ->whereKeyNot($this->getKey())
            ->whereDate('effective_from', Carbon::parse($to)->toDateString())
            ->exists();
    }

    /**
     * Whether this rate has priced a sale, or could have.
     *
     * In force since today or earlier, or pointed at by an order. Judged on
     * the dates as saved, not as they are being edited — moving the start
     * into the future is not a way round it.
     */
    public function hasApplied(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $from = $this->getRawOriginal('effective_from');

        return ($from !== null && Carbon::parse($from)->startOfDay()->lte(today()))
            || Order::query()->where('tax_rate_id', $this->getKey())->exists();
    }

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
            // Never needed while the database refuses overlapping rows, and
            // there so that if two ever matched, every quote would pick the
            // same one rather than whichever Postgres returned first.
            ->orderByDesc('effective_from')
            ->first();
    }
}
