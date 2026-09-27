<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database reads a timestamp in the application's zone, whatever the
 * server was set to.
 *
 * Every timestamp goes to Postgres without an offset, and Postgres reads one
 * of those in the session's zone. Unset, that is the server's default, which
 * is whatever the machine was set to when Postgres was installed: UTC in the
 * containers CI and the dev stack use, Lagos on a server in Lagos. So the
 * suite passed or failed on which database it ran against, and on a server in
 * Lagos every instant was stored an hour early. Run against one, six tests
 * elsewhere in the suite failed for real: a password-reset link was already
 * out of date when it was sent, a checkout hold ran out before the payment
 * page did, and the calendar file put the night an hour out.
 *
 * libpq takes a session's starting zone from PGTZ, so setting it here stands
 * in for such a server without needing one. That holds where CI runs; PHP on
 * Windows keeps putenv to itself, so there these pass without proving much.
 */
class DatabaseTimezoneTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PGTZ');
        DB::purge();

        parent::tearDown();
    }

    private function onAServerIn(string $zone): void
    {
        putenv("PGTZ={$zone}");
        DB::purge();
    }

    public function test_the_session_is_in_the_applications_zone_on_a_server_that_is_not(): void
    {
        $this->onAServerIn('Africa/Lagos');

        $this->assertSame(config('app.timezone'), DB::selectOne('show timezone')->TimeZone);
    }

    public function test_an_instant_written_is_the_instant_read_back(): void
    {
        $this->onAServerIn('America/Toronto');

        $at = CarbonImmutable::parse('2026-10-03 22:00:00', 'UTC');

        $read = DB::selectOne('select ?::timestamptz as at', [$at->format('Y-m-d H:i:s')])->at;

        // Read in Toronto's zone this came back four hours late.
        $this->assertTrue($at->equalTo(CarbonImmutable::parse($read)), "Wrote {$at}, read back {$read}.");
    }
}
