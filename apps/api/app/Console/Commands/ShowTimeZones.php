<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Which edition of the time-zone database PHP reads, and whether the database
 * server agrees with it.
 *
 * Every event time the API works out goes through it — Carbon asks PHP, and
 * PHP asks this — while the site's server works out the same times in Node,
 * from ICU's copy. Two editions put one night at two different times: once
 * British Columbia stopped changing its clocks, a PHP carrying the edition
 * from before that said 8 p.m. where the site said 9. The images read one
 * edition, ops/docker/tzdata-edition; this is how a running container shows
 * it does. `node tz-version.mjs` in the site's container is the other half,
 * and prints the same two lines (docs/OPERATIONS.md, "Time zones").
 *
 * Postgres is a third copy, and no image sets it. It turns times into local
 * days itself — the site's today, this weekend and this month, the sales
 * report's days, the dashboards' buckets — so an event it puts on the other
 * side of midnight is on the wrong shelf. It cannot name its edition, so it is
 * asked for the same night and has to agree with PHP.
 */
class ShowTimeZones extends Command
{
    protected $signature = 'app:time-zones
        {--expect= : The edition PHP should be reading, as IANA names it (2026d); anything else, or a database that disagrees, fails}';

    protected $description = 'Show which edition of the time-zone database PHP reads, and whether the database agrees';

    /** After 1 November 2026, when British Columbia did not fall back: 8 p.m. before 2026b, 9 p.m. from it. */
    private const MOMENT = '2026-11-15 04:00';

    private const ZONE = 'America/Vancouver';

    public function handle(): int
    {
        $version = timezone_version_get();
        $edition = self::edition($version);
        $source = self::source($version, extension_loaded('timezonedb'));

        $moment = new DateTimeImmutable(self::MOMENT, new DateTimeZone('UTC'));
        $php = self::clock($moment->setTimezone(new DateTimeZone(self::ZONE)));
        $database = $this->databaseClock($moment);

        $this->line($version === '0.system'
            ? "PHP reads time zones from {$source}, which do not say which edition they are ({$version})."
            : "PHP reads time zones from {$source}: IANA's {$edition} ({$version}).");
        $this->line("Vancouver at 04:00 UTC on 15 November 2026: {$php}.");
        $this->line($database === null
            ? 'The database did not answer, so its copy is unchecked.'
            : "The database, from its own copy: {$database}.");

        if ($database !== null && $database !== $php) {
            $this->error("The database reads another edition: it puts that night at {$database}, PHP at {$php}.");
        }

        $expected = $this->option('expect');

        if (! is_string($expected) || $expected === '') {
            return self::SUCCESS;
        }

        if ($expected !== $edition) {
            $this->error("That is not {$expected}.");

            return self::FAILURE;
        }

        return $database === $php ? self::SUCCESS : self::FAILURE;
    }

    /**
     * An edition as IANA names it.
     *
     * PHP and PECL count them instead — 2026.4 is the fourth of 2026, which
     * IANA calls 2026d — and an operator comparing this with Node, or with
     * ops/docker/tzdata-edition, is reading IANA's names. Anything else
     * (a distribution's "0.system") is shown as it is.
     */
    public static function edition(string $version): string
    {
        if (preg_match('/^(\d{4})\.(\d{1,2})$/', $version, $parts) !== 1) {
            return $version;
        }

        $count = (int) $parts[2];

        return $count >= 1 && $count <= 26 ? $parts[1].chr(ord('a') + $count - 1) : $version;
    }

    /**
     * Where PHP's copy comes from.
     *
     * The extension replaces the compiled-in copy when it is loaded, and only
     * then. A distribution's PHP is patched to read the system's
     * /usr/share/zoneinfo instead, and says "0.system": that edition is the
     * host's tzdata package, not anything PHP shipped with.
     */
    public static function source(string $version, bool $timezonedb): string
    {
        return match (true) {
            $timezonedb => 'the timezonedb extension',
            $version === '0.system' => "the system's zone files",
            default => 'the copy compiled into PHP '.PHP_VERSION,
        };
    }

    /** A clock time and the offset that made it, written the same way on both sides. */
    private static function clock(DateTimeImmutable $time): string
    {
        return $time->format('H:i, \U\T\CP');
    }

    /**
     * The same moment in Vancouver, as the database works it out.
     *
     * Postgres hands back the wall clock alone; the gap between that and the
     * moment itself is the offset it applied. Null when it does not answer,
     * which the command reports rather than throwing: the other two lines
     * are still worth having.
     */
    private function databaseClock(DateTimeImmutable $moment): ?string
    {
        try {
            $wall = DB::scalar(
                "select (?::timestamptz at time zone '".self::ZONE."')::text",
                [$moment->format('Y-m-d H:i:sP')],
            );
        } catch (Throwable) {
            return null;
        }

        $seconds = (new DateTimeImmutable((string) $wall, new DateTimeZone('UTC')))->getTimestamp() - $moment->getTimestamp();
        $offset = sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));

        return self::clock($moment->setTimezone(new DateTimeZone($offset)));
    }
}
