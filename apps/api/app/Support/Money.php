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
