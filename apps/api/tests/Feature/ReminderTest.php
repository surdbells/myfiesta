<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\EventReminderMail;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReviewsEvents;
use Tests\TestCase;

/**
 * Reminding people, and letting them stop.
 *
 * The sending is the easy half. What is tested hardest here is the half that
 * costs money when it is wrong: sending twice, sending late, and sending to
 * somebody who asked you not to.
 */
class ReminderTest extends TestCase
{
    use RefreshDatabase, ReviewsEvents;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'description' => 'Afrobeats until late.',
            'currency' => 'CAD',
            'starts_at' => now()->addDays(10),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function holder(string $email, string $status = 'valid'): Ticket
    {
        return Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->type->id,
            'code' => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'owner_email' => $email,
            'holder_name' => 'Someone',
            'status' => $status,
            'admits' => 1,
            'admitted_count' => 0,
        ]);
    }

    private function reminderDue(int $offsetMinutes): EventReminder
    {
        $reminder = $this->event->reminders()->create([
            'offset_minutes' => $offsetMinutes,
            'status' => 'scheduled',
        ]);

        // Moved so the reminder's moment is just behind us, rather than
        // travelling the clock — which would also move every other timestamp.
        $this->event->update([
            'starts_at' => now()->addMinutes($offsetMinutes)->subMinute(),
        ]);

        return $reminder->fresh()->load('event');
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

    // --- sending -----------------------------------------------------------

    public function test_a_due_reminder_reaches_everyone_holding_a_ticket(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        $this->reminderDue(24 * 60);

        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertQueued(EventReminderMail::class, 2);
    }

    public function test_a_reminder_that_is_not_due_yet_waits(): void
    {
        $this->holder('ada@example.com');

        // The event is ten days out; a one-day reminder has nine days to wait.
        $this->event->reminders()->create(['offset_minutes' => 24 * 60, 'status' => 'scheduled']);

        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertNothingQueued();
    }

    public function test_nobody_is_reminded_twice(): void
    {
        $this->holder('ada@example.com');
        $this->reminderDue(24 * 60);

        app(ReminderDispatcher::class)->dispatchDue();
        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertQueued(EventReminderMail::class, 1);
    }

    public function test_a_run_that_died_halfway_resumes_rather_than_restarts(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        $reminder = $this->reminderDue(24 * 60);

        // What a crashed worker leaves behind: one person emailed, the reminder
        // still claimed. Without a record per recipient, the retry sends Ada a
        // second copy.
        DB::table('reminder_deliveries')->insert([
            'reminder_id' => $reminder->id,
            'email' => 'ada@example.com',
            'created_at' => now(),
        ]);
        $reminder->update(['status' => 'scheduled']);

        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertQueued(EventReminderMail::class, 1);
        Mail::assertQueued(
            EventReminderMail::class,
            fn ($mail) => $mail->hasTo('chidi@example.com'),
        );
    }

    public function test_a_reminder_that_is_badly_late_is_dropped_rather_than_sent(): void
    {
        $this->holder('ada@example.com');

        $reminder = $this->event->reminders()->create([
            'offset_minutes' => 7 * 24 * 60,
            'status' => 'scheduled',
        ]);

        // The queue was down for four days. An email saying the event is a week
        // away, arriving three days before it, teaches the reader to ignore
        // every future one.
        $this->event->update(['starts_at' => now()->addDays(3)]);

        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertNothingQueued();
        $this->assertSame('cancelled', $reminder->fresh()->status);
    }

    public function test_a_slightly_late_reminder_still_goes(): void
    {
        $this->holder('ada@example.com');

        $this->event->reminders()->create(['offset_minutes' => 7 * 24 * 60, 'status' => 'scheduled']);

        // Twenty minutes behind on a week's notice is still a week's notice.
        $this->event->update(['starts_at' => now()->addDays(7)->subMinutes(20)]);

        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertQueued(EventReminderMail::class, 1);
    }

    public function test_a_refunded_ticket_reminds_nobody(): void
    {
        $this->holder('ada@example.com', 'refunded');
        $this->holder('chidi@example.com');
        $this->reminderDue(24 * 60);

        app(ReminderDispatcher::class)->dispatchDue();

        // Being reminded to attend something you were refunded for is the
        // worst email this platform could send.
        Mail::assertQueued(EventReminderMail::class, 1);
        Mail::assertNotQueued(
            EventReminderMail::class,
            fn ($mail) => $mail->hasTo('ada@example.com'),
        );
    }

    public function test_an_unpublished_event_reminds_nobody(): void
    {
        $this->holder('ada@example.com');
        $this->reminderDue(24 * 60);
        $this->event->update(['status' => 'draft']);

        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertNothingQueued();
    }

    public function test_moving_an_event_moves_its_reminders(): void
    {
        $this->holder('ada@example.com');
        $this->event->reminders()->create(['offset_minutes' => 24 * 60, 'status' => 'scheduled']);

        // Pushed back a month. The reminder must follow, not fire on the old
        // date — which is exactly when getting it wrong is most visible.
        $this->event->update(['starts_at' => now()->addDays(40)]);

        app(ReminderDispatcher::class)->dispatchDue();
        Mail::assertNothingQueued();

        $this->assertTrue(
            EventReminder::first()->sendAt()->isSameDay(now()->addDays(39)),
        );
    }

    // --- opting out --------------------------------------------------------

    public function test_somebody_who_opted_out_is_not_reminded(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');

        EmailPreference::forEmail('ada@example.com')
            ->update(['reminders_opted_out_at' => now()]);

        $this->reminderDue(24 * 60);

        app(ReminderDispatcher::class)->dispatchDue();

        Mail::assertQueued(EventReminderMail::class, 1);
        Mail::assertNotQueued(
            EventReminderMail::class,
            fn ($mail) => $mail->hasTo('ada@example.com'),
        );
    }

    public function test_opting_out_is_not_defeated_by_capital_letters(): void
    {
        $this->holder('Ada@Example.com');

        EmailPreference::forEmail('ada@example.com')
            ->update(['reminders_opted_out_at' => now()]);

        $this->reminderDue(24 * 60);
        app(ReminderDispatcher::class)->dispatchDue();

        // From where Ada is sitting, being emailed after unsubscribing is
        // being ignored, whatever the casing said.
        Mail::assertNothingQueued();
    }

    public function test_the_unsubscribe_page_does_not_unsubscribe_anyone(): void
    {
        $preference = EmailPreference::forEmail('ada@example.com');

        $this->get(route('unsubscribe', $preference->token))
            ->assertOk()
            ->assertSee('Stop event reminders?', false);

        // Mail clients and security scanners fetch links in messages. An
        // endpoint that acts on GET unsubscribes people who never clicked.
        $this->assertTrue($preference->fresh()->wantsReminders());
    }

    public function test_one_click_from_an_inbox_actually_unsubscribes(): void
    {
        $preference = EmailPreference::forEmail('ada@example.com');

        // What Gmail posts when it renders its own unsubscribe control: no
        // session, no CSRF token, just the URL from the header.
        $this->post(route('unsubscribe.confirm', $preference->token))
            ->assertOk()
            ->assertSee('Done', false);

        $this->assertFalse($preference->fresh()->wantsReminders());
    }

    public function test_somebody_can_turn_reminders_back_on(): void
    {
        $preference = EmailPreference::forEmail('ada@example.com');
        $this->post(route('unsubscribe.confirm', $preference->token));

        $this->post(route('unsubscribe.resubscribe', $preference->token))->assertOk();

        $this->assertTrue($preference->fresh()->wantsReminders());
    }

    public function test_an_unknown_token_says_nothing_about_whether_it_exists(): void
    {
        $response = $this->get(route('unsubscribe', 'not-a-real-token'));

        // Saying "no such address" would turn this into a way to test whether
        // an address is on the platform.
        $response->assertNotFound()->assertSee('expired', false);
        $response->assertDontSee('address');
    }

    public function test_the_email_carries_the_headers_an_inbox_reads(): void
    {
        $this->holder('ada@example.com');
        $reminder = $this->reminderDue(24 * 60);
        $preference = EmailPreference::forEmail('ada@example.com');

        $headers = (new EventReminderMail($reminder, $preference))->headers();

        // These are what make Gmail show its own unsubscribe button, which is
        // what stops people reporting spam instead — and a domain with a spam
        // reputation cannot deliver ticket confirmations either.
        $this->assertStringContainsString($preference->token, $headers->text['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    // --- the organizer's side ----------------------------------------------

    public function test_publishing_schedules_reminders_without_being_asked(): void
    {
        $event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'later',
            'title' => 'Later',
            'description' => 'A later night.',
            'currency' => 'CAD',
            'starts_at' => now()->addDays(30),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 1000,
            'status' => 'on_sale',
        ]);

        $this->asOrganizer();

        $this->publishThroughReview($event)->assertOk();

        // A week out, the day before, and three hours out. Most organizers
        // should never have to open the reminders screen at all.
        $this->assertSame(
            [10080, 1440, 180],
            $event->reminders()->pluck('offset_minutes')->all(),
        );
    }

    public function test_republishing_does_not_resurrect_a_cancelled_reminder(): void
    {
        $this->asOrganizer();

        TicketType::first()->update(['status' => 'on_sale']);
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->publishThroughReview($this->event)->assertOk();

        $reminder = $this->event->reminders()->where('offset_minutes', 1440)->first();
        $this->deleteJson("/api/organizer/events/{$this->event->id}/reminders/{$reminder->id}")
            ->assertOk();

        // Off sale and back on, unchanged since it was approved.
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])->assertOk();
        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('status', 'published');

        // Turning one off has to stick, or the button does nothing.
        $this->assertSame('cancelled', $reminder->fresh()->status);
    }

    public function test_a_reminder_can_be_added(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", [
            'offset_minutes' => 120,
        ])
            ->assertCreated()
            ->assertJsonPath('label', '2 hours before');
    }

    public function test_a_reminder_for_a_moment_already_past_is_refused(): void
    {
        $this->asOrganizer();

        // Stored and then silently skipped would be worse: it appears in the
        // list, the organizer stops watching for it, and it never sends.
        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", [
            'offset_minutes' => 60 * 24 * 30,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That moment has already passed for this event.');
    }

    public function test_two_reminders_at_the_same_point_are_refused(): void
    {
        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 120])
            ->assertCreated();

        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 120])
            ->assertStatus(422);
    }

    public function test_a_reminder_turned_off_by_mistake_can_be_put_back(): void
    {
        $this->asOrganizer();

        $id = $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 120])
            ->assertCreated()
            ->json('id');

        $this->deleteJson("/api/organizer/events/{$this->event->id}/reminders/{$id}")->assertOk();

        // The same moment again brings the same reminder back, rather than
        // being refused as a duplicate of one nobody will ever receive.
        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 120])
            ->assertCreated()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('status', 'scheduled');

        $this->assertSame(1, $this->event->reminders()->count());
    }

    public function test_a_turned_off_reminder_whose_moment_has_passed_stays_off(): void
    {
        $reminder = $this->event->reminders()->create([
            'offset_minutes' => 60 * 24 * 30,
            'status' => 'cancelled',
        ]);

        $this->asOrganizer();

        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 60 * 24 * 30])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That moment has already passed for this event.');

        $this->assertSame('cancelled', $reminder->fresh()->status);
    }

    public function test_a_sent_reminder_cannot_be_turned_off_afterwards(): void
    {
        $reminder = $this->event->reminders()->create([
            'offset_minutes' => 120,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->asOrganizer();

        $this->deleteJson("/api/organizer/events/{$this->event->id}/reminders/{$reminder->id}")
            ->assertStatus(422);
    }

    public function test_marketing_cannot_change_the_schedule(): void
    {
        $this->asOrganizer(Role::Marketing);

        $this->postJson("/api/organizer/events/{$this->event->id}/reminders", ['offset_minutes' => 120])
            ->assertForbidden();
    }
}
