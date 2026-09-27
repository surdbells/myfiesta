<?php

namespace Tests\Feature;

use App\Services\Legacy\LegacyRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * The judgements the import makes, tested away from the import.
 *
 * Each of these is a decision about data that will exist exactly once, at
 * cutover, and be wrong forever afterwards if it is wrong then. There is no
 * second run to correct a price multiplied by a hundred once organizers are
 * looking at their events.
 */
class LegacyRulesTest extends TestCase
{
    // --- the two money units ------------------------------------------------

    public function test_a_ticket_price_in_dollars_becomes_minor_units(): void
    {
        // event_tickets.ticket_price holds 5, 20, 90, and a students' ticket
        // at 3. Whole dollars.
        $this->assertSame(500, LegacyRules::ticketTypePrice(5, 'CAD')->amount);
        $this->assertSame(9000, LegacyRules::ticketTypePrice(90, 'CAD')->amount);
        $this->assertSame(300, LegacyRules::ticketTypePrice(3, 'CAD')->amount);
    }

    public function test_an_order_cost_is_already_in_minor_units_and_is_left_alone(): void
    {
        // tickets_sales._cost holds 1500, 5000, 506000. Cents — 2,694 rows and
        // not one that is not a multiple of 100.
        //
        // Multiplying these as well would turn $178,538 of real sales into
        // $17.8m, and it would balance perfectly all the way through.
        $this->assertSame(1500, LegacyRules::orderCost(1500, 'CAD')->amount);
        $this->assertSame(506000, LegacyRules::orderCost(506000, 'CAD')->amount);
    }

    // --- the time an event has and the time it does not ---------------------

    public function test_a_start_time_is_read_out_of_free_text(): void
    {
        $at = LegacyRules::startsAt('2024-04-12', '22:00', 'America/Toronto');

        $this->assertSame('2024-04-12 22:00', $at->format('Y-m-d H:i'));
        $this->assertSame('America/Toronto', $at->timezoneName);
    }

    public function test_an_unreadable_start_time_falls_back_to_the_day_rather_than_dropping_the_event(): void
    {
        foreach ([null, '', '   ', 'doors at 10ish'] as $unusable) {
            $at = LegacyRules::startsAt('2024-04-12', $unusable, 'America/Toronto');

            $this->assertSame(
                '2024-04-12 00:00',
                $at->format('Y-m-d H:i'),
                'An event at midnight is wrong by hours. An event that vanishes is wrong by the whole event.',
            );
        }
    }

    public function test_an_end_time_is_invented_because_the_source_has_none(): void
    {
        $start = CarbonImmutable::parse('2024-04-12 22:00', 'America/Toronto');

        $this->assertSame(
            '2024-04-13 04:00',
            LegacyRules::endsAt($start)->format('Y-m-d H:i'),
            'A club night that opens at ten closes at four.',
        );
    }

    public function test_a_missing_timezone_is_guessed_from_the_currency(): void
    {
        $this->assertSame('America/Toronto', LegacyRules::timezoneFor(null, 'CAD'));
        $this->assertSame('Africa/Lagos', LegacyRules::timezoneFor('', 'NGN'));

        // A zone that is actually recorded always wins over the guess.
        $this->assertSame('America/Vancouver', LegacyRules::timezoneFor('America/Vancouver', 'CAD'));
    }

    // --- statuses -----------------------------------------------------------

    public function test_an_unrecognised_event_status_becomes_a_draft(): void
    {
        $this->assertSame('published', LegacyRules::eventStatus('PUBLISHED'));
        $this->assertSame('cancelled', LegacyRules::eventStatus('CANCELLED'));
        $this->assertSame('draft', LegacyRules::eventStatus('DRAFT'));

        // The old column is a varchar with no constraint. A guess that
        // publishes puts an event in front of buyers; a guess that drafts does
        // not, so that is the direction to guess in.
        foreach ([null, '', 'ARCHIVED', 'whatever'] as $unknown) {
            $this->assertSame('draft', LegacyRules::eventStatus($unknown));
        }
    }

    public function test_an_abandoned_checkout_imports_as_cancelled_not_pending(): void
    {
        // 981 of 2,694 sales rows never left PENDING, some of them two years
        // old. Carried across as pending they would be live orders holding
        // inventory and appearing in an organizer's list of who is coming.
        $this->assertSame('cancelled', LegacyRules::orderStatus('PENDING'));
        $this->assertSame('cancelled', LegacyRules::orderStatus(null));

        $this->assertSame('paid', LegacyRules::orderStatus('paid'));
    }

    // --- passwords ----------------------------------------------------------

    public function test_bcrypt_comes_across_and_sha1_does_not(): void
    {
        $bcrypt = '$2y$12$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU';

        $this->assertSame($bcrypt, LegacyRules::importablePassword($bcrypt));

        // 34 of the accounts are unsalted SHA-1, from before the platform
        // moved. Recovered at billions of guesses a second over a leaked
        // table, and these accounts have settlement details behind them.
        $this->assertNull(LegacyRules::importablePassword(sha1('hunter2')));
        $this->assertNull(LegacyRules::importablePassword(md5('hunter2')));
        $this->assertNull(LegacyRules::importablePassword(''));
        $this->assertNull(LegacyRules::importablePassword(null));
    }

    // --- slugs --------------------------------------------------------------

    public function test_a_slug_survives_a_title_with_nothing_usable_in_it(): void
    {
        $this->assertSame('summer-fest-2024', LegacyRules::slugify('Summer Fest 2024!', 'x'));
        $this->assertSame('event-99', LegacyRules::slugify('🎉🎉🎉', 'event-99'));
        $this->assertSame('event-99', LegacyRules::slugify('   ', 'event-99'));
    }

    // --- why a row did not come across -------------------------------------

    public function test_a_failure_reason_names_the_constraint_and_keeps_none_of_the_row(): void
    {
        // What Postgres says about a ticket whose code is already taken: the
        // constraint on the first line, the code itself on the next, and
        // Laravel adds the statement with its bindings after that.
        $driver = new \PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "tickets_code_unique"'
            ."\nDETAIL:  Key (code)=(MFST-TESTCODE) already exists."
        );

        $e = new QueryException(
            'pgsql',
            'insert into "tickets" ("code") values (?)',
            ['MFST-TESTCODE'],
            $driver,
        );

        $reason = LegacyRules::failureReason($e);

        $this->assertStringContainsString('tickets_code_unique', $reason);
        $this->assertStringNotContainsString('MFST-TESTCODE', $reason);
        $this->assertStringNotContainsString('insert into', $reason);
    }

    public function test_a_failure_that_is_not_the_database_says_what_it_was(): void
    {
        $this->assertSame(
            'RuntimeException: server closed the connection unexpectedly',
            LegacyRules::failureReason(new \RuntimeException("server closed the connection unexpectedly\nsecond line")),
        );
    }
}
