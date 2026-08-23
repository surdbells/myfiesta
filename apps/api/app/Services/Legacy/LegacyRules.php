<?php

namespace App\Services\Legacy;

use App\Support\Money;
use Carbon\CarbonImmutable;

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
