<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A datetime that is stored in UTC whatever zone it arrives in.
 *
 * Eloquent's default cast writes the *wall clock* of the instance it is given
 * and discards the zone: hand it 9pm in Toronto and the column ends up holding
 * 21:00 UTC, which is 5pm in Toronto. Nothing errors, the value reads back
 * cleanly, and the event is simply four hours out — the kind of wrong that is
 * only found by somebody standing outside a venue.
 *
 * Every caller could remember to call ->utc() first. This exists because
 * eventually one will not, and the recurrence generator was that caller: it
 * takes instants from a library that quite correctly returns them in the
 * venue's zone.
 *
 * Reading is unchanged — the column is UTC and comes back as UTC. Anything
 * wanting the venue's clock asks for it explicitly, which is the only way to
 * be sure which zone a displayed time is in.
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            // A bare string with no offset is read as UTC rather than as the
            // server's local time, so a deployment in a different region does
            // not silently shift every date it is given.
            : CarbonImmutable::parse($value, 'UTC');

        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
