<?php

namespace Tests\Feature;

use App\Console\Commands\ShowTimeZones;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `php artisan app:time-zones`, what an operator runs after a deploy.
 *
 * It is compared by eye with `node tz-version.mjs` in the site's container,
 * which names editions the way IANA does, so this has to as well: PHP says
 * 2026.4 where Node and ops/docker/tzdata-edition say 2026d. The edition PHP
 * reads here is whatever this machine has, so the tests read it rather than
 * name one; and the database's answer is given to the command, since this
 * machine's Postgres need not carry PHP's edition (here, today, it does not).
 */
class TimeZonesTest extends TestCase
{
    public function test_an_edition_is_named_as_iana_names_it(): void
    {
        $this->assertSame('2026a', ShowTimeZones::edition('2026.1'));
        $this->assertSame('2026d', ShowTimeZones::edition('2026.4'));
        $this->assertSame('2025c', ShowTimeZones::edition('2025.3'));

        // A distribution's PHP reading the system's files says so, not a number.
        $this->assertSame('0.system', ShowTimeZones::edition('0.system'));
    }

    public function test_it_says_where_php_takes_its_copy_from(): void
    {
        $this->assertSame('the timezonedb extension', ShowTimeZones::source('2026.4', true));
        $this->assertSame('the copy compiled into PHP '.PHP_VERSION, ShowTimeZones::source('2026.1', false));

        // Not PHP's copy at all: the host's tzdata, whatever edition that is.
        $this->assertSame("the system's zone files", ShowTimeZones::source('0.system', false));
    }

    public function test_it_says_which_edition_php_reads_and_one_night_in_it(): void
    {
        $version = timezone_version_get();

        // A distribution's PHP (Ubuntu's, on CI) reads the system's files and
        // reports 0.system, which names no edition; the command says so rather
        // than inventing one. Only a numbered copy has an IANA name to print.
        $says = $version === '0.system'
            ? 'which do not say which edition they are (0.system)'
            : "IANA's ".ShowTimeZones::edition($version)." ({$version})";

        $this->artisan('app:time-zones')
            ->expectsOutputToContain($says)
            ->expectsOutputToContain('Vancouver at 04:00 UTC on 15 November 2026: '.$this->phpNight()->format('H:i, \U\T\CP').'.')
            ->expectsOutputToContain('The database, from its own copy: ')
            ->assertSuccessful();
    }

    public function test_it_passes_when_php_reads_the_edition_expected_and_the_database_agrees(): void
    {
        $this->databaseSays($this->phpNight()->format('Y-m-d H:i:s'));

        $this->artisan('app:time-zones', ['--expect' => ShowTimeZones::edition(timezone_version_get())])
            ->expectsOutputToContain('The database, from its own copy: '.$this->phpNight()->format('H:i, \U\T\CP').'.')
            ->doesntExpectOutputToContain('another edition')
            ->assertSuccessful();
    }

    public function test_it_fails_when_php_reads_another_edition(): void
    {
        $this->databaseSays($this->phpNight()->format('Y-m-d H:i:s'));

        $this->artisan('app:time-zones', ['--expect' => '1999z'])
            ->expectsOutputToContain('That is not 1999z.')
            ->assertFailed();
    }

    public function test_it_fails_when_the_database_puts_the_night_elsewhere(): void
    {
        // Seven in the evening is neither edition's answer (8 before 2026b,
        // 9 from it), so PHP disagrees with it whichever this machine has.
        $this->databaseSays('2026-11-14 19:00:00');

        $this->artisan('app:time-zones', ['--expect' => ShowTimeZones::edition(timezone_version_get())])
            ->expectsOutputToContain('The database, from its own copy: 19:00, UTC-09:00.')
            ->expectsOutputToContain('The database reads another edition: it puts that night at 19:00, UTC-09:00, PHP at '.$this->phpNight()->format('H:i, \U\T\CP').'.')
            ->assertFailed();
    }

    public function test_a_database_that_does_not_answer_leaves_the_check_unfinished(): void
    {
        DB::shouldReceive('scalar')->once()->andThrow(new RuntimeException('connection refused'));

        $this->artisan('app:time-zones', ['--expect' => ShowTimeZones::edition(timezone_version_get())])
            ->expectsOutputToContain('The database did not answer, so its copy is unchecked.')
            ->doesntExpectOutputToContain('connection refused')
            ->assertFailed();
    }

    /** The night as this PHP puts it, which is what the database has to match. */
    private function phpNight(): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-11-15 04:00', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('America/Vancouver'));
    }

    /** Postgres's answer to `at time zone`: a wall clock, with no offset. */
    private function databaseSays(string $wall): void
    {
        DB::shouldReceive('scalar')->once()->andReturn($wall);
    }
}
