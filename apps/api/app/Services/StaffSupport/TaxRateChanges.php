<?php

namespace App\Services\StaffSupport;

use App\Enums\PlatformRole;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Audit\Auditor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Changing what buyers are charged in tax, and the record of who did.
 *
 * A rate changes by being superseded: the row in force is closed on a date and
 * a new row takes over from it, so every order placed before still points at
 * the rate that was actually applied. Finance and administrators only — the
 * same people the tax-rate screen lets edit — checked here as well as by the
 * button, because the button is not the only way to call this.
 */
class TaxRateChanges
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Close the rate in force and open its replacement.
     *
     * Never backdated: orders placed today were charged the old rate, and a
     * replacement that started yesterday would say otherwise.
     *
     * @throws StaffActionRefused
     */
    public function supersede(TaxRate $rate, User $staff, int $basisPoints, CarbonInterface $from): TaxRate
    {
        self::authorize($staff);

        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw StaffActionRefused::because('A rate is between 0% and 100%.');
        }

        $from = Carbon::parse($from)->startOfDay();

        if ($from->lt(today())) {
            throw StaffActionRefused::because('A new rate starts today or later. Orders already placed keep the rate they were charged.');
        }

        [$previous, $replacement] = DB::transaction(function () use ($rate, $basisPoints, $from) {
            /** @var TaxRate $locked */
            $locked = TaxRate::query()->whereKey($rate->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->effective_to !== null) {
                throw StaffActionRefused::because('This rate has already been superseded. Supersede the one that replaced it.');
            }

            if ($from->lte($locked->effective_from)) {
                throw StaffActionRefused::because('The new rate has to start after this one does ('.$locked->effective_from->format('j M Y').').');
            }

            $locked->update(['effective_to' => $from]);

            $replacement = TaxRate::create([
                'country' => $locked->country,
                'subdivision' => $locked->subdivision,
                'default_currency' => $locked->default_currency,
                'name' => $locked->name,
                'rate_bps' => $basisPoints,
                'inclusive' => $locked->inclusive,
                'effective_from' => $from,
            ]);

            return [$locked, $replacement];
        });

        $this->auditor->record('tax_rate.superseded', $replacement, $staff, metadata: [
            'jurisdiction' => self::jurisdiction($replacement),
            'replaces' => $previous->id,
            'from_bps' => $previous->rate_bps,
            'to_bps' => $basisPoints,
            'effective_from' => $from->toDateString(),
        ]);

        return $replacement;
    }

    /** A rate typed into the create form. */
    public function recordCreated(TaxRate $rate, User $staff): void
    {
        $this->auditor->record('tax_rate.created', $rate, $staff, metadata: [
            'jurisdiction' => self::jurisdiction($rate),
            'rate_bps' => $rate->rate_bps,
            'inclusive' => $rate->inclusive,
            'effective_from' => $rate->effective_from?->toDateString(),
            'effective_to' => $rate->effective_to?->toDateString(),
        ]);
    }

    /** An edit through the form: what each changed field was, and became. */
    public function recordEdited(TaxRate $rate, User $staff): void
    {
        $changes = collect($rate->getChanges())
            ->except(['updated_at'])
            ->map(fn ($value, string $field) => [
                'from' => self::plain($rate->getPrevious()[$field] ?? null),
                'to' => self::plain($value),
            ]);

        if ($changes->isEmpty()) {
            return;
        }

        $this->auditor->record('tax_rate.edited', $rate, $staff, metadata: [
            'jurisdiction' => self::jurisdiction($rate),
            'changes' => $changes->all(),
        ]);
    }

    public static function allows(?User $staff): bool
    {
        return $staff !== null
            && $staff->deleted_at === null
            && $staff->hasPlatformRole(PlatformRole::Admin, PlatformRole::Finance);
    }

    /** @throws StaffActionRefused */
    public static function authorize(?User $staff): void
    {
        if (! self::allows($staff)) {
            throw StaffActionRefused::because('Only finance and administrators change tax rates.');
        }
    }

    private static function jurisdiction(TaxRate $rate): string
    {
        return $rate->subdivision ? "{$rate->country}-{$rate->subdivision}" : (string) $rate->country;
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }
}
