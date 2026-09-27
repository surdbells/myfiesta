<?php

namespace App\Services\Payments;

use Illuminate\Support\Str;

/**
 * The night's name as it appears on a bank statement.
 *
 * "I don't recognise this charge" is one of the commonest reasons a buyer
 * gives their bank, and it is usually true: a line reading MYFIESTA and
 * nothing else, six weeks later, names no night anybody remembers. Stripe
 * lets each card payment carry a suffix after the account's own prefix —
 * MYFIESTA* AFRO FEST — and a statement that names the night is one nobody
 * needs to ask their bank about.
 *
 * Stripe's rules, which it enforces by refusing the checkout: the whole line
 * is at most 22 characters, counting the prefix, the "* " Stripe puts after
 * it and the suffix; Latin characters only; none of < > \ ' " *; and the
 * suffix needs a letter of its own. So the name is spelled in plain letters,
 * digits and spaces, cut at a word where one fits, and left off altogether
 * when nothing recognisable is left — the prefix alone is still a valid line.
 */
final class StatementDescriptor
{
    /** The longest line a card statement is given. */
    public const MAX = 22;

    /** What Stripe puts between the prefix and the suffix. */
    private const SEPARATOR = '* ';

    /** The longest prefix Stripe's dashboard accepts, assumed when none is known. */
    private const LONGEST_PREFIX = 10;

    /**
     * The suffix for a night, or null when its name leaves nothing usable.
     *
     * @param  string|null  $prefix  the shortened descriptor set in Stripe's dashboard
     */
    public static function suffix(string $title, ?string $prefix): ?string
    {
        $room = self::MAX - self::prefixLength($prefix) - strlen(self::SEPARATOR);

        if ($room < 1) {
            return null;
        }

        // Accents to their plain letters, so Fête reads FETE rather than
        // losing a letter; apostrophes closed up, so Ada's reads ADAS; and
        // anything else that is not a letter or a digit becomes a space.
        $plain = Str::ascii($title);
        $plain = str_replace(["'", '`'], '', $plain);
        $plain = (string) preg_replace('/[^A-Za-z0-9]+/', ' ', $plain);
        $plain = strtoupper(trim($plain));

        $suffix = self::fit($plain, $room);

        return $suffix !== '' && preg_match('/[A-Z]/', $suffix) === 1 ? $suffix : null;
    }

    /** The whole line, as a bank statement would show it. */
    public static function line(string $title, ?string $prefix): string
    {
        $prefix = self::cleanPrefix($prefix);
        $suffix = self::suffix($title, $prefix);

        return $suffix === null ? $prefix : $prefix.self::SEPARATOR.$suffix;
    }

    /**
     * As many whole words as fit — AFRO FEST rather than AFRO FEST LA — unless
     * whole words leave less than half the room, when the name is simply cut:
     * ADAS AFROBEA says more than ADAS.
     */
    private static function fit(string $words, int $room): string
    {
        if (strlen($words) <= $room) {
            return $words;
        }

        $space = strrpos(substr($words, 0, $room + 1), ' ');

        $fitted = $space === false || $space * 2 < $room
            ? substr($words, 0, $room)
            : substr($words, 0, $space);

        return rtrim($fitted);
    }

    private static function prefixLength(?string $prefix): int
    {
        $clean = self::cleanPrefix($prefix);

        return $clean === '' ? self::LONGEST_PREFIX : min(strlen($clean), self::LONGEST_PREFIX);
    }

    private static function cleanPrefix(?string $prefix): string
    {
        return strtoupper(trim((string) preg_replace('/[^A-Za-z0-9 .-]+/', '', Str::ascii((string) $prefix))));
    }
}
