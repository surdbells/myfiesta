<?php

namespace App\Services\Sms;

/**
 * Turning what somebody typed into a number a provider will accept.
 *
 * Deliberately conservative: anything that is not already unambiguous is
 * refused rather than guessed at. A guess that lands on somebody else's phone
 * sends them a stranger's ticket link, which is worse than no text at all.
 */
class PhoneNumber
{
    /** @return string|null E.164 with the plus, or null if it cannot be trusted */
    public static function e164(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9+]/', '', $input) ?? '';

        // Already international.
        if (str_starts_with($digits, '+')) {
            $number = '+'.preg_replace('/[^0-9]/', '', substr($digits, 1));

            return self::plausible($number) ? $number : null;
        }

        // 00 as the international prefix, which half of the world dials.
        if (str_starts_with($digits, '00')) {
            $number = '+'.substr($digits, 2);

            return self::plausible($number) ? $number : null;
        }

        // Anything else is a local number whose country we would have to
        // assume. We do not: an 11-digit string is a Nigerian mobile and a
        // British landline and several other things besides.
        return null;
    }

    private static function plausible(string $number): bool
    {
        // E.164 allows fifteen digits, and nothing real is shorter than eight.
        return (bool) preg_match('/^\+[1-9][0-9]{7,14}$/', $number);
    }
}
