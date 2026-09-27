<?php

namespace App\Services\Checkout;

use App\Services\Settings\PlatformSettings;
use App\Support\Money;

/**
 * One tax on an order: which, at what rate, on what, and how much.
 *
 * An order used to carry one tax figure and a pointer to one rate. That was
 * enough while every jurisdiction had a single tax, and stops being enough the
 * day Quebec's QST sits beside GST, or the service charge is taxed as well as
 * the tickets: a receipt has to show each of them with its own rate, and a
 * figure that adds three things together cannot be taken apart again.
 *
 * Rates are in millionths (50000 is 5%) rather than basis points, because
 * QST is 9.975%. A basis-point rate converts exactly, and the arithmetic below
 * gives the same cent as Money::percentage for any rate that has one.
 */
final readonly class TaxLine
{
    public const ON_TICKETS = 'tickets';

    public const ON_SERVICE_CHARGE = 'service_charge';

    public function __construct(
        /** As the buyer reads it: GST, HST, QST, VAT. */
        public string $name,
        public int $ratePpm,
        /** tickets, or service_charge. */
        public string $on,
        /** What the rate was applied to: the price before this tax, or the price it is inside. */
        public Money $base,
        public Money $amount,
        /** Inside the base rather than added to it — Nigeria. */
        public bool $inclusive,
        /** The tax_rates row, where one applied. QST is a setting, not a row. */
        public ?string $taxRateId = null,
    ) {}

    /**
     * Work one out.
     *
     * Added on top, it is the rate of the base, rounded half up. Inside the
     * price it is extracted rather than added: at 7.5%, the tax inside 1075
     * is 75, not 80.
     */
    public static function charge(string $name, int $ratePpm, string $on, Money $base, bool $inclusive, ?string $taxRateId = null): self
    {
        $amount = $inclusive
            ? (int) round($base->amount * $ratePpm / (1_000_000 + $ratePpm))
            : intdiv($base->amount * $ratePpm + 500_000, 1_000_000);

        return new self($name, $ratePpm, $on, $base, new Money($amount, $base->currency), $inclusive, $taxRateId);
    }

    /** "5", "9.975", "13", "7.5" */
    public function percent(): string
    {
        return PlatformSettings::ppmToPercent($this->ratePpm);
    }

    /**
     * What is kept on the order.
     *
     * @return array{name: string, rate_ppm: int, on: string, base: int, amount: int, inclusive: bool, tax_rate_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'rate_ppm' => $this->ratePpm,
            'on' => $this->on,
            'base' => $this->base->amount,
            'amount' => $this->amount->amount,
            'inclusive' => $this->inclusive,
            'tax_rate_id' => $this->taxRateId,
        ];
    }

    /** @param  array<string, mixed>  $stored */
    public static function fromArray(array $stored, string $currency): self
    {
        return new self(
            name: (string) $stored['name'],
            ratePpm: (int) $stored['rate_ppm'],
            on: (string) $stored['on'],
            base: new Money((int) $stored['base'], $currency),
            amount: new Money((int) $stored['amount'], $currency),
            inclusive: (bool) $stored['inclusive'],
            taxRateId: $stored['tax_rate_id'] ?? null,
        );
    }

    /** @param  list<self>  $lines */
    public static function sum(array $lines, string $currency): Money
    {
        return array_reduce(
            $lines,
            fn (Money $carry, self $line) => $carry->plus($line->amount),
            Money::zero($currency),
        );
    }
}
