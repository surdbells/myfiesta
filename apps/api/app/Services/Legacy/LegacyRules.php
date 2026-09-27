<?php

namespace App\Services\Legacy;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Every decision the import has to make, in one place and none of it doing I/O.
 *
 * The old schema and the new one disagree about more than names. It holds
 * money in two different units, has no end time for an event, joins on
 * varchar strings, and stores two generations of password hash. Each of those
 * is a judgement, and a judgement buried in a loop over 2,694 rows is one
 * nobody can review or test.
 *
 * So they live here, as pure functions, and the importer is left doing nothing
 * but reading and writing.
 */
final class LegacyRules
{
    /**
     * The previous platform's event_types, by id, as this platform's categories.
     *
     * Its events stored the id — "2", "8" — and the importer copied that into
     * the category, so every imported event would have been filed under a
     * number. Shows and the arts fold together, as do its two music types.
     * 15 was "Standard (Couple)", a ticket type that had leaked into the list,
     * and files under nothing.
     */
    private const CATEGORIES = [
        1 => 'Nightlife',
        2 => 'Concert',
        3 => 'Performing arts',
        5 => 'Music',
        7 => 'Online',
        8 => 'Party',
        9 => 'Comedy',
        10 => 'Food & drink',
        11 => 'Music',
        12 => 'Community & culture',
        13 => 'Classes & workshops',
        14 => 'Performing arts',
        16 => 'Sports',
    ];

    /** A legacy category — an event_types id, or a name — as one of ours, or null. */
    public static function category(int|string|null $legacy): ?string
    {
        if ($legacy === null || trim((string) $legacy) === '') {
            return null;
        }

        if (ctype_digit(trim((string) $legacy))) {
            return self::CATEGORIES[(int) $legacy] ?? null;
        }

        $name = mb_strtolower(trim((string) $legacy));

        foreach (config('events.categories') as $category) {
            if (mb_strtolower($category) === $name) {
                return $category;
            }
        }

        return null;
    }

    /**
     * A ticket type's price, converted to minor units.
     *
     * `event_tickets.ticket_price` is in whole dollars — the rows read 5, 20,
     * 90, and a students' ticket at 3. `tickets_sales._cost` on the very same
     * sale is in cents: 2,694 rows and not one that is not a multiple of 100.
     * Two units for money in one system, and the only way to tell them apart
     * is to know which table you are in.
     */
    public static function ticketTypePrice(int|string|null $legacyPrice, string $currency): Money
    {
        return new Money(((int) $legacyPrice) * 100, $currency);
    }

    /**
     * An order's total, which is already in minor units.
     *
     * Left alone rather than "normalised", because multiplying it would turn
     * $178,538 of real sales into $17.8m.
     */
    public static function orderCost(int|string|null $legacyCost, string $currency): Money
    {
        return new Money((int) $legacyCost, $currency);
    }

    /**
     * When an event started, from a date column and a free-text time.
     *
     * `_start_time` is a varchar and holds whatever was typed: "22:00", "10pm",
     * sometimes nothing. A row that cannot be read falls back to the start of
     * the day rather than being dropped — an event at midnight is wrong by
     * hours, an event that vanishes is wrong by the whole event.
     */
    public static function startsAt(string $date, ?string $time, string $timezone): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();

        if ($time === null || trim($time) === '') {
            return $day;
        }

        try {
            $parsed = CarbonImmutable::parse(trim($time), $timezone);
        } catch (\Throwable) {
            return $day;
        }

        return $day->setTime($parsed->hour, $parsed->minute);
    }

    /**
     * When an event ended.
     *
     * There is no answer in the source. The old schema has `_start_date` and
     * `_start_time` and nothing else — an event has a beginning and no end,
     * which is why "is this on tonight?" was never a question the old platform
     * could answer.
     *
     * Six hours is a guess, and it is the honest one for the events this
     * platform actually sells: a club night that opens at ten closes at four.
     * The importer records that it was inferred rather than read, so an
     * organizer correcting it later is fixing an admitted gap and not
     * contradicting data that looks authoritative.
     */
    public static function endsAt(CarbonImmutable $startsAt): CarbonImmutable
    {
        return $startsAt->addHours(6);
    }

    /**
     * The default zone for an event whose `_timezone` is null, which most are.
     *
     * Guessed from the currency, because that is the only other signal the row
     * carries and it is a reliable one: this platform sells in Toronto and
     * Lagos. Wrong for a CAD event in Vancouver, and wrong quietly — which is
     * why it is reported at the end of the run rather than simply applied.
     */
    public static function timezoneFor(?string $legacyTimezone, string $currency): string
    {
        if ($legacyTimezone !== null && trim($legacyTimezone) !== '') {
            return trim($legacyTimezone);
        }

        return $currency === 'NGN' ? 'Africa/Lagos' : 'America/Toronto';
    }

    /**
     * An event's status.
     *
     * The old column is a varchar with a default of 'DRAFT' and no constraint.
     * Anything unrecognised becomes a draft: publishing an event nobody meant
     * to publish puts it in front of buyers, and the safe direction of a
     * guess is the one that does not sell tickets.
     */
    public static function eventStatus(?string $legacyStatus): string
    {
        return match (strtoupper(trim((string) $legacyStatus))) {
            'PUBLISHED', 'LIVE', 'ACTIVE' => 'published',
            'CANCELLED', 'CANCELED' => 'cancelled',
            default => 'draft',
        };
    }

    /**
     * What became of a sale.
     *
     * 981 of the 2,694 sales rows — 36% — never left 'PENDING'/'AWAITING'.
     * They are abandoned checkouts: a basket, a Stripe session that was never
     * completed, and nothing since, some of them two years old.
     *
     * They import as cancelled, not pending. Carrying them across as pending
     * would put 981 orders into a state the new system treats as live, where
     * they would hold inventory and appear in an organizer's list of people
     * who are coming. A checkout abandoned in 2024 has been decided.
     */
    public static function orderStatus(?string $paymentStatus): string
    {
        return match (strtolower(trim((string) $paymentStatus))) {
            'paid', 'success', 'succeeded' => 'paid',
            'refunded' => 'refunded',
            default => 'cancelled',
        };
    }

    /**
     * Whether a legacy password hash can be carried over.
     *
     * The table holds two generations: 34 accounts on bcrypt, from after the
     * platform moved, and 34 still on unsalted SHA-1 from before it.
     *
     * bcrypt comes across as-is and those people never notice. SHA-1 does not.
     * Unsalted SHA-1 over a leaked table is recovered at billions of guesses a
     * second, and these are organizer accounts with settlement details behind
     * them. Accepting one even once, to rehash it on first sign-in, means the
     * new system verifies a hash that a commodity GPU has already broken.
     *
     * So those accounts arrive with no password and go through a reset. It is
     * friction for 34 people, once.
     */
    public static function importablePassword(?string $hash): ?string
    {
        $hash = trim((string) $hash);

        // bcrypt, in any of its prefixes. Laravel verifies these unchanged.
        if (preg_match('/^\$2[aby]\$\d{2}\$/', $hash) === 1) {
            return $hash;
        }

        return null;
    }

    /**
     * The buyer, out of a pipe-delimited string.
     *
     * `tickets_sales._guest` is not an email address. It is
     * `First|Last|email`, on all 2,694 rows without exception — which means a
     * check for "does this validate as an email" fails on every one of them,
     * and an importer that falls back to a placeholder throws away the
     * address every ticket on the platform was ever sent to.
     *
     * @return array{name: string, email: ?string}
     */
    public static function parseBuyer(?string $guest): array
    {
        $parts = array_map(trim(...), explode('|', (string) $guest));

        // Take the address from wherever it is rather than from position 3.
        // A name with a pipe in it, or an older two-part row, would otherwise
        // shift everything along by one.
        $email = null;

        foreach ($parts as $i => $part) {
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower($part);
                unset($parts[$i]);

                break;
            }
        }

        $name = trim(implode(' ', array_filter($parts)));

        return ['name' => $name, 'email' => $email];
    }

    /**
     * What was in the basket.
     *
     * Held in `_ticket` (and duplicated in `_quantity`) in two formats, from
     * two eras of the same writer:
     *
     *   pipe    `82|1|50-83|2|20` — lines joined by `-`, fields by `|`,
     *           as id, quantity, and an amount in whole dollars
     *   base64  a JSON array of every ticket type on the event, each with a
     *           `quantity` that is zero for the ones not bought
     *
     * The third pipe field is the ambiguous one: on 478 of the 575 rows where
     * quantity exceeds one it is the line total, and on the other 97 it is the
     * unit price. Nothing in the row says which. So both are tried and the one
     * that reconciles against what was actually charged wins — every one of
     * the 1,761 pipe rows reconciles under exactly one of them.
     *
     * @return list<array{legacy_type_id: string, quantity: int, line_total: int}>
     */
    public static function parseBasket(?string $basket, int $costMinor): array
    {
        $basket = trim((string) $basket);

        if ($basket === '') {
            return [];
        }

        $decoded = self::decodeJsonBasket($basket);

        if ($decoded !== null) {
            return $decoded;
        }

        $segments = array_map(
            fn (string $s) => array_map(trim(...), explode('|', $s)),
            explode('-', $basket),
        );

        foreach ($segments as $parts) {
            if (count($parts) !== 3 || ! is_numeric($parts[1]) || ! is_numeric($parts[2])) {
                return [];
            }
        }

        // Whole dollars in the source; minor units everywhere here.
        $asLineTotal = array_map(fn (array $p) => [
            'legacy_type_id' => $p[0],
            'quantity' => (int) $p[1],
            'line_total' => (int) $p[2] * 100,
        ], $segments);

        $asUnitPrice = array_map(fn (array $p) => [
            'legacy_type_id' => $p[0],
            'quantity' => (int) $p[1],
            'line_total' => (int) $p[1] * (int) $p[2] * 100,
        ], $segments);

        $sum = fn (array $lines) => array_sum(array_column($lines, 'line_total'));

        if ($sum($asLineTotal) === $costMinor) {
            return $asLineTotal;
        }

        if ($sum($asUnitPrice) === $costMinor) {
            return $asUnitPrice;
        }

        // Neither reconciles. Return the more common reading rather than
        // nothing — an order with lines that are slightly wrong is still an
        // order somebody can look at, and the importer records that it did
        // not balance.
        return $asLineTotal;
    }

    /**
     * The base64 form: a snapshot of every ticket type, most with quantity 0.
     *
     * Returns null when the string is not that format, so the caller can fall
     * through to the pipe reading.
     *
     * @return list<array{legacy_type_id: string, quantity: int, line_total: int}>|null
     */
    private static function decodeJsonBasket(string $basket): ?array
    {
        if (! str_starts_with($basket, 'W3si')) {
            return null;
        }

        $json = base64_decode($basket, true);

        if ($json === false) {
            return null;
        }

        $rows = json_decode($json, true);

        if (! is_array($rows)) {
            return null;
        }

        $lines = [];

        foreach ($rows as $row) {
            $quantity = (int) ($row['quantity'] ?? 0);

            // The snapshot lists everything that was on sale. Only the ones
            // with a quantity were bought.
            if ($quantity <= 0 || ! isset($row['id'])) {
                continue;
            }

            // `total` is the line total in dollars, and `price` is a string
            // like "45.00" on newer rows and a bare number on older ones.
            $total = $row['total'] ?? null;

            $lines[] = [
                'legacy_type_id' => (string) $row['id'],
                'quantity' => $quantity,
                'line_total' => $total !== null
                    ? (int) round(((float) $total) * 100)
                    : (int) round(((float) ($row['price'] ?? 0)) * 100) * $quantity,
            ];
        }

        return $lines;
    }

    /**
     * The actual image bytes out of a "blob".
     *
     * `events._poster` is declared longblob and does not hold an image. It
     * holds the *text* of a data URI — `data:image/jpeg;base64,/9j/4AAQ...` —
     * and so do `event_posters._poster`, `extra_logo.logo` and
     * `user_accounts.photo`. There is not one raw JPEG header anywhere in that
     * database.
     *
     * Which is also most of why it is 354 MB: base64 costs a third again on
     * top of the images, and the images were already in the table.
     *
     * Writing the column straight to storage produces 286 files full of
     * base64 text that no browser will render, named for a magic number that
     * was never there.
     *
     * @return array{bytes: string, mime: ?string}|null
     */
    public static function decodeImage(?string $blob): ?array
    {
        $blob = (string) $blob;

        if (strlen($blob) < 32) {
            return null;
        }

        if (preg_match('#^data:([-\w./+]+)?;base64,#i', $blob, $m) === 1) {
            $bytes = base64_decode(substr($blob, strlen($m[0])), true);

            if ($bytes === false || strlen($bytes) < 32) {
                return null;
            }

            // The declared type is a claim, not evidence. Sniffed below and
            // only used when sniffing finds nothing.
            return ['bytes' => $bytes, 'mime' => self::sniffMime($bytes) ?? ($m[1] ?? null)];
        }

        // Bare base64 with no header, which a handful of rows use.
        if (preg_match('#^[A-Za-z0-9+/\r\n]+={0,2}$#', substr($blob, 0, 128)) === 1) {
            $bytes = base64_decode($blob, true);

            if ($bytes !== false && self::sniffMime($bytes) !== null) {
                return ['bytes' => $bytes, 'mime' => self::sniffMime($bytes)];
            }
        }

        // Already an image.
        $mime = self::sniffMime($blob);

        return $mime === null ? null : ['bytes' => $blob, 'mime' => $mime];
    }

    /** The type from the first bytes, which is the only trustworthy source. */
    public static function sniffMime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, 'GIF8') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP') => 'image/webp',
            default => null,
        };
    }

    /** The file extension for a sniffed mime type. */
    public static function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * A slug for an organization or event that has none.
     *
     * The old events table has a `_slug` text column that is frequently null.
     * Uniqueness is the importer's problem, not this function's — it only has
     * to produce something legible from a title.
     */
    public static function slugify(string $value, string $fallback): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? $fallback : substr($slug, 0, 80);
    }

    /**
     * Why a row did not come across, in words that are safe to keep.
     *
     * A database error arrives with the row attached: Postgres adds a DETAIL
     * line quoting the offending values, and Laravel appends the whole
     * statement with its bindings filled in. For a ticket that is the ticket
     * code, and ticket codes are not written anywhere they could be read back
     * out of. The first line of the driver's message names the constraint,
     * which is what somebody fixing the row needs; the values are in the
     * source, under the id recorded beside this.
     */
    public static function failureReason(\Throwable $e): string
    {
        $message = $e instanceof QueryException && $e->getPrevious() !== null
            ? $e->getPrevious()->getMessage()
            : class_basename($e).': '.$e->getMessage();

        // The first line only. DETAIL, HINT and the statement come after it.
        $first = trim(strtok($message, "\r\n") ?: '');

        // Laravel's own suffix, if the message came without a previous one.
        $first = trim((string) preg_replace('/\s*\(Connection: .*$/s', '', $first));

        if ($first === '') {
            $first = class_basename($e);
        }

        return mb_substr($first, 0, 500);
    }

    /**
     * The country an event was in, from its currency.
     *
     * The old schema has `_province` and nothing above it. Tax resolution needs
     * a country, and the two currencies map cleanly onto the two markets this
     * platform operates in.
     */
    public static function countryFor(string $currency): string
    {
        return match ($currency) {
            'NGN' => 'NG',
            'USD' => 'US',
            default => 'CA',
        };
    }
}
