<?php

namespace App\Services\Analytics\Charts;

use App\Support\Money;

/**
 * Numbers as a chart prints them.
 *
 * Exact where a person reads a value — a tooltip, a table, a tile — and
 * compact on an axis, where "CA$12.5K" is a tick and "CA$12,500.00" is a
 * collision. Money is always minor units in and a currency's own symbol out,
 * one currency at a time.
 */
final class Format
{
    /** The kinds of value a chart can carry. */
    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const PERCENT = 'percent';

    public static function symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'CAD' => 'CA$',
            'NGN' => '₦',
            default => strtoupper($currency).' ',
        };
    }

    /** "CA$1,250.00" — exact. */
    public static function money(int|float $minor, string $currency): string
    {
        return Money::of((int) round($minor), $currency)->format();
    }

    /** "CA$12.5K" — for an axis or a label with no room. */
    public static function moneyCompact(int|float $minor, string $currency): string
    {
        $major = $minor / 100;
        $sign = $major < 0 ? '-' : '';

        if (abs($major) < 1000) {
            $whole = abs($major - round($major)) < 0.005;

            return $sign.self::symbol($currency).number_format(abs($major), $whole ? 0 : 2);
        }

        return $sign.self::symbol($currency).self::compact(abs($major));
    }

    /** 950, 1.2K, 12K, 3.4M, 1.1B. */
    public static function compact(int|float $n): string
    {
        $abs = abs($n);
        $sign = $n < 0 ? '-' : '';

        foreach ([1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $size => $suffix) {
            if ($abs >= $size) {
                $scaled = $abs / $size;

                return $sign.rtrim(rtrim(number_format($scaled, $scaled >= 100 ? 0 : 1), '0'), '.').$suffix;
            }
        }

        return $sign.number_format($abs, abs($abs - round($abs)) < 0.005 ? 0 : 1);
    }

    public static function number(int|float $n): string
    {
        return number_format($n, abs($n - round($n)) < 0.005 ? 0 : 1);
    }

    /** A ratio as a percentage, or an em dash when there is nothing to divide by. */
    public static function percent(?float $ratio, int $decimals = 1): string
    {
        if ($ratio === null) {
            return '—';
        }

        return number_format($ratio * 100, $decimals).'%';
    }

    /** A value in whichever of the three kinds the chart carries. */
    public static function value(int|float|null $value, string $format, ?string $currency = null, bool $compact = false): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            self::MONEY => $compact ? self::moneyCompact($value, (string) $currency) : self::money($value, (string) $currency),
            self::PERCENT => self::percent((float) $value, $compact ? 0 : 1),
            default => $compact ? self::compact($value) : self::number($value),
        };
    }

    /** A signed change: "+12.4%", "−3.0%". */
    public static function delta(?float $change): string
    {
        if ($change === null) {
            return '—';
        }

        return ($change >= 0 ? '+' : '−').number_format(abs($change) * 100, 1).'%';
    }

    /** "Nigeria" for NG, or the code itself where intl is not installed. */
    public static function country(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return 'Unknown';
        }

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'en');

            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /** An SVG coordinate: two decimals at most, no trailing zeros. */
    public static function coord(float $value): string
    {
        $out = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $out === '-0' ? '0' : $out;
    }
}
