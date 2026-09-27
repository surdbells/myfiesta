<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Events\SeriesGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Events that happen again.
 *
 * The tests that earn their place are the clock-change one and the two about
 * regenerating. Everything else in a recurrence feature is arithmetic; those
 * three are where the design either holds or quietly ruins somebody's Friday.
 */
class EventSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        // Today, pinned to a month before the first night. The dates below are
        // fixed because the clock change is, and the series view and ending a
        // series both count from now. Left on the real clock, two of these
        // fail from 31 October 2026, when the first Friday becomes last Friday.
        $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'UTC'));

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        // A Friday, in October, before Toronto's clock goes back.
        $this->event = $this->eventStarting('2026-10-30 21:00:00');
    }

    private function eventStarting(string $localTime, string $slug = 'friday-night'): Event
    {
        $event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => $slug,
            'title' => 'Friday Night',
            'currency' => 'CAD',
            'starts_at' => CarbonImmutable::parse($localTime, 'America/Toronto'),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 2500,
            'status' => 'on_sale',
        ]);

        return $event->refresh();
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(Role $role = Role::Manager): void
    {
        Sanctum::actingAs($this->member($role), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function makeWeekly(Event $event, int $count = 5): EventSeries
    {
        $series = EventSeries::create([
            'organization_id' => $event->organization_id,
            'source_event_id' => $event->id,
            'rrule' => "FREQ=WEEKLY;BYDAY=FR;COUNT={$count}",
            'timezone' => $event->timezone,
            'starts_at' => $event->starts_at,
            'status' => 'active',
        ]);

        $event->update([
            'series_id' => $series->id,
            'series_occurs_at' => $event->starts_at,
        ]);

        return $series->refresh();
    }

    /** Local wall clock of each occurrence, in the venue's zone. */
    private function wallClocks(EventSeries $series): array
    {
        return $series->occurrences()
            ->get()
            ->map(fn (Event $e) => $e->starts_at->timezone($series->timezone)->format('Y-m-d H:i T'))
            ->all();
    }

    // --- the one that matters ----------------------------------------------

    public function test_nine_pm_stays_nine_pm_across_a_clock_change(): void
    {
        $series = $this->makeWeekly($this->event, count: 4);

        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        // Toronto's clocks go back on 1 November 2026. An implementation that
        // adds seven days of seconds produces 8pm from here on — and twice a
        // year, in opposite directions, every regular night in the platform
        // moves by an hour without anybody changing anything.
        $this->assertSame([
            '2026-10-30 21:00 EDT',
            '2026-11-06 21:00 EST',
            '2026-11-13 21:00 EST',
            '2026-11-20 21:00 EST',
        ], $this->wallClocks($series));
    }

    // --- generating --------------------------------------------------------

    public function test_the_source_event_is_the_first_occurrence_not_a_copy_of_it(): void
    {
        $series = $this->makeWeekly($this->event, count: 3);

        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        // Copying the source onto its own slot would give the organizer two
        // events on the night they are looking at.
        $this->assertSame(3, $series->occurrences()->count());
        $this->assertSame(
            $this->event->id,
            $series->occurrences()->first()->id,
        );
    }

    public function test_each_occurrence_is_an_ordinary_event_with_its_own_tickets(): void
    {
        $series = $this->makeWeekly($this->event, count: 3);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $second = $series->occurrences()->skip(1)->first();

        // The whole reason occurrences are materialised: checkout, the door,
        // refunds and the ledger all work on this without knowing series exist.
        $this->assertSame(1, $second->ticketTypes()->count());
        $this->assertNotSame($this->event->slug, $second->slug);
        $this->assertSame('draft', $second->status);
    }

    public function test_running_the_generator_twice_creates_nothing_the_second_time(): void
    {
        $series = $this->makeWeekly($this->event, count: 4);

        $first = app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));
        $second = app(SeriesGenerator::class)->generate($series->refresh(), CarbonImmutable::parse('2027-01-01'));

        $this->assertCount(3, $first);
        $this->assertCount(0, $second);
        $this->assertSame(4, $series->occurrences()->count());
    }

    public function test_an_occurrence_the_organizer_moved_is_left_alone(): void
    {
        $series = $this->makeWeekly($this->event, count: 4);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $moved = $series->occurrences()->skip(1)->first();
        $moved->update([
            'starts_at' => $moved->starts_at->addDay(),
            'title' => 'Saturday, exceptionally',
        ]);

        app(SeriesGenerator::class)->generate($series->refresh(), CarbonImmutable::parse('2027-01-01'));

        // Slots are matched on when the rule scheduled them, not on when the
        // event now starts — otherwise moving one night to the Saturday makes
        // a second event appear on the Friday.
        $this->assertSame(4, $series->occurrences()->count());
        $this->assertSame('Saturday, exceptionally', $moved->fresh()->title);
    }

    public function test_editing_an_occurrence_is_never_undone_by_a_later_run(): void
    {
        $series = $this->makeWeekly($this->event, count: 4);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $quiet = $series->occurrences()->skip(2)->first();
        $quiet->ticketTypes()->first()->update(['price_amount' => 1000]);

        app(SeriesGenerator::class)->generate($series->refresh(), CarbonImmutable::parse('2027-01-01'));

        // A generator that kept occurrences "in sync" would put the price back
        // on a schedule, and the organizer would never work out what was doing
        // it.
        $this->assertSame(1000, $quiet->ticketTypes()->first()->price_amount);
    }

    public function test_only_the_window_is_materialised_not_the_whole_future(): void
    {
        $series = EventSeries::create([
            'organization_id' => $this->org->id,
            'source_event_id' => $this->event->id,
            // No COUNT and no UNTIL: this repeats forever.
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
            'timezone' => 'America/Toronto',
            'starts_at' => $this->event->starts_at,
            'status' => 'active',
        ]);

        $this->event->update([
            'series_id' => $series->id,
            'series_occurs_at' => $this->event->starts_at,
        ]);

        app(SeriesGenerator::class)->generate(
            $series->refresh(),
            CarbonImmutable::parse('2026-12-31'),
        );

        // The nine Fridays from 30 October to Christmas Day, and not one week
        // of the infinite tail beyond them.
        $this->assertSame(9, $series->occurrences()->count());
        $this->assertTrue(
            $series->occurrences()->orderByDesc('starts_at')->first()->starts_at->year === 2026,
        );
    }

    // --- skipping ----------------------------------------------------------

    public function test_a_skipped_date_stays_skipped_when_the_generator_runs_again(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 4);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $boxingDay = $series->occurrences()->skip(2)->first();

        $this->postJson("/api/organizer/events/{$this->event->id}/series/skip", [
            'occurrence_id' => $boxingDay->id,
            'reason' => 'Venue closed',
        ])->assertOk();

        $this->assertSame(3, $series->occurrences()->count());

        app(SeriesGenerator::class)->generate($series->refresh(), CarbonImmutable::parse('2027-01-01'));

        // Without the exception recorded, the next run helpfully puts it back.
        $this->assertSame(3, $series->occurrences()->count());
    }

    public function test_a_date_with_tickets_sold_cannot_be_skipped_silently(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 4);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $sold = $series->occurrences()->skip(1)->first();

        Ticket::create([
            'event_id' => $sold->id,
            'ticket_type_id' => $sold->ticketTypes()->first()->id,
            'code' => 'AAAA-BBBBBBBB',
            'owner_email' => 'ada@example.com',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ]);

        // Cancelling a night people hold tickets to is a refund conversation,
        // not a scheduling one.
        $this->postJson("/api/organizer/events/{$this->event->id}/series/skip", [
            'occurrence_id' => $sold->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tickets have been sold for that date. Refund them first.');

        $this->assertSame(4, $series->occurrences()->count());
    }

    // --- ending ------------------------------------------------------------

    public function test_ending_a_series_leaves_dates_people_have_bought_into(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 4);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $sold = $series->occurrences()->skip(1)->first();

        Ticket::create([
            'event_id' => $sold->id,
            'ticket_type_id' => $sold->ticketTypes()->first()->id,
            'code' => 'CCCC-DDDDDDDD',
            'owner_email' => 'chidi@example.com',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ]);

        $this->deleteJson("/api/organizer/events/{$this->event->id}/series")->assertOk();

        // Ending a residency is not asking to cancel next Friday on the people
        // who already bought for it.
        $this->assertSame('ended', $series->fresh()->status);
        $this->assertNotNull($sold->fresh());
        $this->assertSame(2, $series->occurrences()->count());
    }

    public function test_an_ended_series_stops_generating(): void
    {
        $series = $this->makeWeekly($this->event, count: 8);
        $series->update(['status' => 'ended']);

        app(SeriesGenerator::class)->generateAll();

        $this->assertSame(1, $series->occurrences()->count());
    }

    // --- the endpoint ------------------------------------------------------

    public function test_an_organizer_can_make_an_event_weekly(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'weekly',
            'count' => 4,
        ])
            ->assertCreated()
            ->assertJsonPath('series.rrule', 'FREQ=WEEKLY;BYDAY=FR;COUNT=4')
            ->assertJsonPath('created', 3);
    }

    public function test_monthly_repeats_on_the_same_weekday_not_the_same_number(): void
    {
        $this->asOrganizer();

        // 30 October 2026 is the fifth Friday. A residency is "the last Friday
        // of the month", never "the 30th" — which does not exist in February.
        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'monthly',
            'count' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('series.rrule', 'FREQ=MONTHLY;BYDAY=5FR;COUNT=3');
    }

    public function test_fortnightly_is_every_other_week(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'fortnightly',
            'count' => 3,
        ])->assertCreated();

        $series = $this->event->fresh()->series;

        $this->assertSame([
            '2026-10-30 21:00 EDT',
            '2026-11-13 21:00 EST',
            '2026-11-27 21:00 EST',
        ], $this->wallClocks($series));
    }

    public function test_an_event_cannot_be_put_in_two_series(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'weekly', 'count' => 3,
        ])->assertCreated();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'monthly', 'count' => 3,
        ])->assertStatus(422);
    }

    public function test_marketing_cannot_turn_one_event_into_fifty(): void
    {
        $this->asOrganizer(Role::Marketing);

        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'weekly', 'count' => 50,
        ])->assertForbidden();

        $this->assertSame(0, EventSeries::count());
    }

    public function test_another_organizations_event_cannot_be_made_to_repeat(): void
    {
        $otherOrg = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $stranger = User::factory()->create();
        $otherOrg->members()->attach($stranger->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($stranger->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'weekly', 'count' => 3,
        ])->assertForbidden();
    }

    public function test_the_series_view_says_which_nights_were_moved(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", [
            'frequency' => 'weekly', 'count' => 3,
        ])->assertCreated();

        $series = $this->event->fresh()->series;
        $moved = $series->occurrences()->skip(1)->first();
        $moved->update(['starts_at' => $moved->starts_at->addDay()]);

        // A series where one night sits on a Saturday should not look identical
        // to one where they all sit on Fridays.
        $this->getJson("/api/organizer/events/{$this->event->id}/series")
            ->assertOk()
            ->assertJsonPath('series.occurrences.0.moved', false)
            ->assertJsonPath('series.occurrences.1.moved', true);
    }
}
