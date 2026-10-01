<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\EventScheduledSale;
use App\Models\Event;
use App\Models\EventReview;
use App\Models\EventSeries;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Events\EventReviews;
use App\Services\Events\SeriesGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
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

    // --- changing a series ---------------------------------------------------

    private function sold(Event $date, string $status = 'valid'): void
    {
        Ticket::create([
            'event_id' => $date->id,
            'ticket_type_id' => $date->ticketTypes()->first()->id,
            'code' => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'owner_email' => 'ada@example.com',
            'status' => $status,
            'admits' => 1,
            'admitted_count' => 0,
        ]);
    }

    /** The dates of a series by when the rule scheduled them, on the venue's calendar. */
    private function dates(EventSeries $series): array
    {
        return $series->occurrences()->get()
            ->map(fn (Event $e) => $e->series_occurs_at->timezone($series->timezone)->format('Y-m-d'))
            ->all();
    }

    public function test_shortening_a_series_removes_the_unsold_dates_past_its_end_and_keeps_the_sold_ones(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 6);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        [, , , $fourth, $fifth] = $series->occurrences()->get()->all();
        // Listed for resale is still somebody's ticket until it sells.
        $this->sold($fifth, 'listed');

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['count' => 3])
            ->assertOk()
            ->assertJsonPath('series.count', 3)
            ->assertJsonPath('removed', 2)
            ->assertJsonPath('kept', 1)
            ->assertJsonPath('message', 'It now runs for 3 dates. 2 dates after that were removed. 1 date after that is kept, because people hold tickets for it.');

        $this->assertNull(Event::find($fourth->id));
        $this->assertNotNull(Event::find($fifth->id));
        $this->assertSame(['2026-10-30', '2026-11-06', '2026-11-13', '2026-11-27'], $this->dates($series));
        $this->assertSame('FREQ=WEEKLY;BYDAY=FR;COUNT=3', $series->fresh()->rrule);
    }

    public function test_ending_on_a_day_keeps_that_days_night(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 6);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        // The whole of 13 November in Toronto: its 9pm night stays.
        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['until' => '2026-11-13'])
            ->assertOk()
            ->assertJsonPath('series.until', '2026-11-13')
            ->assertJsonPath('series.count', null);

        $this->assertSame(['2026-10-30', '2026-11-06', '2026-11-13'], $this->dates($series));
    }

    public function test_lengthening_a_series_adds_its_dates_now(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 2);
        app(SeriesGenerator::class)->generate($series);

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['count' => 4])
            ->assertOk()
            ->assertJsonPath('created', 2);

        $this->assertSame(4, $series->occurrences()->count());
    }

    public function test_how_often_it_repeats_does_not_change(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 4);

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'monthly', 'count' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stop this series and start a new one.');

        $this->assertSame('FREQ=WEEKLY;BYDAY=FR;COUNT=4', $series->fresh()->rrule);

        // The one it already is, said again, is no change.
        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'weekly'])->assertOk();
    }

    public function test_a_series_that_has_stopped_is_not_changed(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 4);
        $series->update(['status' => 'ended']);

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['count' => 6])->assertStatus(422);
    }

    // --- putting its dates on sale -------------------------------------------

    public function test_putting_dates_on_sale_by_themselves_gives_each_its_time(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 3);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => true, 'on_sale_days_before' => 14])
            ->assertOk()
            ->assertJsonPath('series.auto_publish', true)
            ->assertJsonPath('series.on_sale_days_before', 14)
            ->assertJsonPath('message', 'Each date goes on sale by itself 14 days before it.');

        [$source, $second, $third] = $series->occurrences()->get()->all();

        // The night the organizer built is theirs to send.
        $this->assertNull($source->publish_at);
        $this->assertNull($second->publish_scheduled_by);

        // 14 days on the venue's calendar: 9pm there, though Toronto's clock
        // went back in between (1 November), for the dates already made and
        // the ones made later alike.
        $this->assertSame('2026-10-23 21:00 EDT', $this->localTime($second->publish_at));
        $this->assertSame('2026-10-30 21:00 EDT', $this->localTime($third->publish_at));

        // A date made later is given its time as it is made.
        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['count' => 4])->assertOk();
        $fourth = $series->occurrences()->reorder('series_occurs_at', 'desc')->first();
        $this->assertSame('2026-11-06 21:00 EST', $this->localTime($fourth->publish_at));
    }

    private function localTime(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->timezone('America/Toronto')->format('Y-m-d H:i T');
    }

    public function test_a_date_taken_off_sale_or_sent_back_is_not_given_a_time(): void
    {
        $this->asOrganizer(Role::Owner);

        $series = $this->makeWeekly($this->event, count: 4);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        [, $second, $third, $fourth] = $series->occurrences()->get()->all();

        // On sale once, and taken off by the organizer: theirs to put back.
        $second->forceFill(['status' => 'draft', 'published_at' => now()->subDay()])->save();
        // Sent back by staff: waits for the organizer to change it.
        EventReview::create([
            'event_id' => $third->id,
            'action' => EventReview::REJECTED,
            'reason' => 'The poster uses another promoter’s artwork.',
            'created_at' => now(),
        ]);

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => true, 'on_sale_days_before' => 7])->assertOk();

        $this->assertNull($second->fresh()->publish_at);
        $this->assertNull($third->fresh()->publish_at);
        $this->assertNotNull($fourth->fresh()->publish_at);

        // Nor when the days change later.
        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => true, 'on_sale_days_before' => 3])->assertOk();

        $this->assertNull($second->fresh()->publish_at);
        $this->assertNull($third->fresh()->publish_at);
    }

    public function test_a_date_made_inside_its_window_goes_on_sale_at_the_next_run(): void
    {
        $series = $this->makeWeekly($this->event, count: 2);
        $series->update(['auto_publish' => true, 'on_sale_days_before' => 60, 'auto_publish_by' => $this->member(Role::Owner)->id]);

        app(SeriesGenerator::class)->generate($series->refresh(), CarbonImmutable::parse('2027-01-01'));

        $second = $series->occurrences()->reorder('series_occurs_at', 'desc')->first();
        $this->assertTrue($second->publish_at->equalTo(now()));
    }

    public function test_turning_it_off_takes_back_only_the_times_the_series_gave(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 3);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => true])->assertOk();

        [, $second, $third] = $series->occurrences()->get()->all();
        $own = CarbonImmutable::parse('2026-10-20 10:00:00', 'America/Toronto');
        $third->forceFill(['publish_at' => $own, 'publish_scheduled_by' => $this->member(Role::Owner)->id])->save();

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Its dates no longer go on sale by themselves.');

        $this->assertNull($second->fresh()->publish_at);
        $this->assertTrue($third->fresh()->publish_at->equalTo($own));
        $this->assertNull($series->fresh()->auto_publish_by);
    }

    public function test_only_somebody_who_can_put_events_on_sale_turns_it_on(): void
    {
        $this->asOrganizer(Role::Marketing);

        $series = $this->makeWeekly($this->event, count: 3);

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => true])->assertForbidden();
        $this->assertFalse($series->fresh()->auto_publish);
    }

    public function test_a_series_date_goes_on_sale_as_the_approved_night_at_its_time(): void
    {
        Mail::fake();
        $this->asOrganizer(Role::Owner);

        // The source, approved as it is.
        app(EventReviews::class)->recordApproval($this->event, null, EventReviews::VIA_EXISTING);

        $series = $this->makeWeekly($this->event, count: 3);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => true, 'on_sale_days_before' => 14])->assertOk();

        [, $second, $third] = $series->occurrences()->get()->all();
        $third->update(['title' => 'Friday Night: special guest', 'description' => '<p>With a guest from Lagos.</p>']);

        // 14 days before the third: both are due.
        $this->travelTo($third->starts_at->subDays(14)->addMinute());
        $this->artisan('events:go-live')->assertSuccessful();

        $this->assertSame('published', $second->fresh()->status);
        $review = EventReview::where('event_id', $second->id)->sole();
        $this->assertSame(['approved', 'series'], [$review->action, $review->via]);

        // Changed, so it went to be looked at.
        $this->assertSame('in_review', $third->fresh()->status);

        // One email for the two, not one each, saying how long a review takes.
        Mail::assertQueued(EventScheduledSale::class, 1);
        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => count($mail->dates) === 2
            && str_contains($mail->render(), 'Most reviews are done within'));
    }

    public function test_dates_that_all_went_on_sale_are_not_said_to_wait_for_a_review(): void
    {
        Mail::fake();
        $this->asOrganizer(Role::Owner);

        app(EventReviews::class)->recordApproval($this->event, null, EventReviews::VIA_EXISTING);

        $series = $this->makeWeekly($this->event, count: 3);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['auto_publish' => true, 'on_sale_days_before' => 14])->assertOk();

        [, $second, $third] = $series->occurrences()->get()->all();
        $this->travelTo($third->fresh()->publish_at->addMinute());
        $this->artisan('events:go-live')->assertSuccessful();

        $this->assertSame(['published', 'published'], [$second->fresh()->status, $third->fresh()->status]);
        Mail::assertQueued(EventScheduledSale::class, fn (EventScheduledSale $mail) => count($mail->dates) === 2
            && ! str_contains($mail->render(), 'review'));
    }

    public function test_a_series_whose_member_lost_the_right_stops_putting_dates_on_sale(): void
    {
        Mail::fake();

        $this->member(Role::Owner); // who hears about it
        $owner = $this->member(Role::Owner);
        $series = $this->makeWeekly($this->event, count: 3);
        $series->update(['auto_publish' => true, 'auto_publish_by' => $owner->id]);
        app(SeriesGenerator::class)->generate($series->refresh(), CarbonImmutable::parse('2027-01-01'));

        $this->org->members()->updateExistingPivot($owner->id, ['role' => Role::Marketing->value]);

        $this->artisan('events:go-live')->assertSuccessful();

        $this->assertFalse($series->fresh()->auto_publish);
        $this->assertSame(0, $series->occurrences()->whereNotNull('publish_at')->count());
        $this->assertSame(0, $series->occurrences()->where('status', '!=', 'draft')->whereKeyNot($this->event->id)->count());
        Mail::assertQueued(EventScheduledSale::class, 1);
    }

    public function test_stopping_a_series_keeps_a_date_with_a_listed_ticket_and_takes_back_its_times(): void
    {
        $this->asOrganizer();

        $series = $this->makeWeekly($this->event, count: 4);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));
        $series->update(['auto_publish' => true]);

        $listed = $series->occurrences()->skip(1)->first();
        $this->sold($listed, 'listed');
        $listed->forceFill(['publish_at' => now()->addDay()])->save();

        $this->postJson("/api/organizer/events/{$this->event->id}/series/skip", ['occurrence_id' => $listed->id])
            ->assertStatus(422);

        $this->deleteJson("/api/organizer/events/{$this->event->id}/series")->assertOk();

        $this->assertNotNull($listed->fresh());
        $this->assertNull($listed->fresh()->publish_at);
        $this->assertFalse($series->fresh()->auto_publish);
        $this->assertSame(2, $series->occurrences()->count());
    }

    public function test_the_series_view_says_how_it_was_set_up(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'fortnightly', 'count' => 3])->assertCreated();

        $this->getJson("/api/organizer/events/{$this->event->id}/series")
            ->assertOk()
            ->assertJsonPath('series.frequency', 'fortnightly')
            ->assertJsonPath('series.count', 3)
            ->assertJsonPath('series.until', null)
            ->assertJsonPath('series.auto_publish', false)
            ->assertJsonPath('series.on_sale_days_before', null)
            ->assertJsonPath('series.occurrences.1.publish_at', null);
    }

    /**
     * Changing a series answers with every field the contract declares, so
     * the console never mistakes "nothing" for an older server.
     */
    public function test_changing_a_series_answers_as_the_contract_says(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/series", ['frequency' => 'weekly', 'count' => 4])->assertCreated();

        $body = $this->patchJson("/api/organizer/events/{$this->event->id}/series", ['count' => 3])->assertOk()->json();

        $spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));
        $answer = $spec['paths']['/api/organizer/events/{event}/series']['patch']['responses']['200']['content']['application/json']['schema'];
        $series = $spec['components']['schemas']['OrganizerSeries'];

        foreach ($answer['required'] as $field) {
            $this->assertArrayHasKey($field, $body, "The answer declares '{$field}' and the API does not return it.");
        }

        foreach ($series['required'] as $field) {
            $this->assertArrayHasKey($field, $body['series'], "OrganizerSeries declares '{$field}' and the API does not return it.");
        }

        foreach ($series['properties']['occurrences']['items']['required'] as $field) {
            $this->assertArrayHasKey($field, $body['series']['occurrences'][0], "A series date declares '{$field}' and the API does not return it.");
        }
    }

    // --- more dates ----------------------------------------------------------

    public function test_an_event_page_lists_its_other_dates_on_sale_and_no_others(): void
    {
        $series = $this->makeWeekly($this->event, count: 5);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-01-01'));

        [, $second, , $fourth] = $series->occurrences()->get()->all();
        $second->update(['status' => 'published', 'published_at' => now()]);
        $fourth->update(['status' => 'published', 'published_at' => now()]);
        // Sold out, and said so.
        $fourth->ticketTypes()->update(['status' => 'sold_out']);
        // The third and fifth are drafts: nobody's business yet.

        $this->getJson('/api/events/friday-night')
            ->assertOk()
            ->assertJsonCount(2, 'data.other_dates')
            ->assertJsonPath('data.other_dates.0.slug', $second->slug)
            ->assertJsonPath('data.other_dates.1.slug', $fourth->slug)
            ->assertJsonPath('data.other_dates.1.availability.state', 'sold_out');

        // From another date, the source is one of its others.
        $this->getJson("/api/events/{$second->slug}")
            ->assertOk()
            ->assertJsonPath('data.other_dates.0.slug', 'friday-night');
    }

    public function test_more_dates_lists_the_next_eight_still_to_come(): void
    {
        $series = $this->makeWeekly($this->event, count: 12);
        app(SeriesGenerator::class)->generate($series, CarbonImmutable::parse('2027-02-01'));

        $dates = $series->occurrences()->get()->all();
        $this->assertCount(12, $dates);
        Event::whereKey(array_map(fn (Event $date) => $date->id, $dates))->update(['status' => 'published', 'published_at' => now()]);

        // An hour into the second night: it and the first are under way.
        $this->travelTo($dates[1]->starts_at->addHour());

        $listed = $this->getJson("/api/events/{$dates[2]->slug}")
            ->assertOk()
            ->assertJsonCount(8, 'data.other_dates')
            ->json('data.other_dates.*.slug');

        // The fourth to the eleventh: soonest first, nothing begun, no more than eight.
        $this->assertSame(array_map(fn (Event $date) => $date->slug, array_slice($dates, 3, 8)), $listed);
    }

    public function test_a_night_that_does_not_repeat_has_no_other_dates(): void
    {
        $this->getJson('/api/events/friday-night')
            ->assertOk()
            ->assertJsonPath('data.other_dates', null);
    }
}
