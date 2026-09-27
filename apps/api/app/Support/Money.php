<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An amount in minor units, with its currency.
 *
 * Money is never a bare number in this codebase. The platform this replaces
 * stored cents in some columns and dollars in others, returned preformatted
 * currency strings from the API, and re-parsed them in the client — so the same
 * value meant different things at different layers.
 *
 * Integers only: floats do not survive being multiplied by a tax rate.
 */
final readonly class Money
{
    /** Currencies whose prices are written in whole units unless there is a remainder. */
    private const WHOLE_UNITS = ['NGN'];

    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Currency must be ISO 4217, got '{$currency}'.");
        }
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Apply a rate in basis points, rounding half up.
     *
     * Used for both tax and commission. Basis points rather than a percentage
     * keeps the multiplication in integer space until the final division.
     */
    public function percentage(int $basisPoints): self
    {
        return new self(intdiv($this->amount * $basisPoints + 5000, 10000), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /**
     * For a sentence a person reads: "$1,250.00", "₦45,000", "₦206.25".
     *
     * Written the way the three apps write it (packages/shared/src/money.ts),
     * because an email is read next to the screen it links to, and a receipt
     * that says "CA$113.00" above a ticket that says "$113.00" reads as two
     * different amounts. So: the symbol each market writes by hand, and naira
     * without kobo when there are none — Nigerian prices are set and written
     * in whole naira. An amount that does carry kobo keeps both digits, so
     * nothing is rounded where somebody could add it up. Dollars always show
     * cents.
     *
     * Not for anything a machine reads back.
     */
    public function format(): string
    {
        $minor = abs($this->amount);
        $units = number_format(intdiv($minor, 100));

        $text = in_array($this->currency, self::WHOLE_UNITS, true) && $minor % 100 === 0
            ? $units
            : $units.'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return ($this->amount < 0 ? '-' : '').self::symbol($this->currency).$text;
    }

    /**
     * "$", "₦" — the symbol alone, for a chart's axis.
     *
     * "$" alone is only ambiguous where US dollars are also on sale, and
     * nothing here is sold in anything but CAD and NGN. Anything else is
     * written as its code, so it is never mistaken for either.
     */
    public static function symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'CAD' => '$',
            'NGN' => '₦',
            default => strtoupper($currency).' ',
        };
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Refusing to combine {$this->currency} with {$other->currency}. ".
                'Balances are per-currency and are never summed across.'
            );
        }
    }
}
