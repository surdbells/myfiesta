<?php

namespace App\Services\Analytics;

/**
 * The currencies the platform sells in, and the clock each one keeps.
 *
 * A market is read in its own currency and its own day. Canadian dollars and
 * naira are never added together — a total across them is a number that
 * means nothing — and a Lagos sale at 11pm belongs to that Lagos day, not to
 * the next UTC one.
 */
final class Market
{
    /** Currency => the time zone its days are counted in. */
    public const CURRENCIES = [
        'CAD' => 'America/Toronto',
        'NGN' => 'Africa/Lagos',
    ];

    public const DEFAULT = 'CAD';

    /** @return array<string, string> currency => label, for a picker */
    public static function options(): array
    {
        return [
            'CAD' => 'CAD · Canada',
            'NGN' => 'NGN · Nigeria',
        ];
    }

    /** A currency the platform sells in, or the default. */
    public static function normalize(?string $currency): string
    {
        $currency = strtoupper((string) $currency);

        return array_key_exists($currency, self::CURRENCIES) ? $currency : self::DEFAULT;
    }

    public static function timezone(string $currency): string
    {
        return self::CURRENCIES[self::normalize($currency)];
    }
}
