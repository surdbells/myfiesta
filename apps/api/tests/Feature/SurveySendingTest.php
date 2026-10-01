<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\SurveyInvitationMail;
use App\Models\EmailPreference;
use App\Models\EventSurvey;
use App\Models\SurveyInvitation;
use App\Services\Surveys\SurveySender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RunsSurveys;
use Tests\TestCase;

/**
 * The survey after a night: asked once, the morning after, of the people the
 * door let in, and never of anybody who said no to mail like it.
 */
class SurveySendingTest extends TestCase
{
    use RefreshDatabase, RunsSurveys;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = $this->aNight();
    }

    public function test_a_night_is_asked_about_once_after_its_delay_and_only_by_the_people_let_in(): void
    {
        $this->holder('ada@example.com');
        $this->holder('Ada@Example.com');            // the same person, a second ticket
        $this->holder('chidi@example.com', admitted: 0);
        $this->holder('emeka@example.com', admitted: 1, status: 'refunded');
        $this->writtenDown();

        // The morning after, but not yet the hour it was set for.
        $this->hoursAfter(17.5);
        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->hoursAfter(18.1);
        $this->artisan('surveys:send-due')->assertSuccessful();

        Mail::assertQueued(SurveyInvitationMail::class, 1);
        Mail::assertQueued(SurveyInvitationMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
        $this->assertSame(['ada@example.com'], SurveyInvitation::pluck('email')->all());

        // Every hour after: nothing more.
        $this->hoursAfter(19.1);
        $this->artisan('surveys:send-due')->assertSuccessful();
        $this->hoursAfter(30);
        $this->artisan('surveys:send-due')->assertSuccessful();

        Mail::assertQueued(SurveyInvitationMail::class, 1);
        $this->assertNotNull(EventSurvey::sole()->sent_at);
    }

    public function test_a_night_whose_door_scanned_nobody_asks_everybody_who_held_a_ticket(): void
    {
        $this->holder('ada@example.com', admitted: 0);
        $this->holder('chidi@example.com', admitted: 0, status: 'listed');
        $this->holder('emeka@example.com', admitted: 0, status: 'refunded');
        $this->holder('funmi@example.com', admitted: 0, status: 'transferred');
        $this->writtenDown();

        $this->hoursAfter(19);
        $this->artisan('surveys:send-due')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['ada@example.com', 'chidi@example.com'], SurveyInvitation::pluck('email')->all());
        Mail::assertQueued(SurveyInvitationMail::class, 2);
    }

    public function test_nobody_who_said_no_to_mail_they_did_not_ask_for_is_asked(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        EmailPreference::forEmail('Chidi@example.com')->update(['marketing_opted_out_at' => now()]);
        // Saying no to reminders is a different no.
        EmailPreference::forEmail('ada@example.com')->update(['reminders_opted_out_at' => now()]);
        $this->writtenDown();

        $this->hoursAfter(19);
        $this->artisan('surveys:send-due')->assertSuccessful();

        Mail::assertQueued(SurveyInvitationMail::class, 1);
        Mail::assertQueued(SurveyInvitationMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
        $this->assertSame(0, SurveyInvitation::where('email', 'chidi@example.com')->count());
    }

    public function test_a_night_the_door_has_not_written_down_yet_waits_for_it(): void
    {
        $this->holder('ada@example.com');

        $this->hoursAfter(19);
        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->writtenDown();
        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertQueued(SurveyInvitationMail::class, 1);
    }

    public function test_the_organizers_delay_decides_when(): void
    {
        $this->holder('ada@example.com');
        $this->writtenDown();
        EventSurvey::create(['event_id' => $this->event->id, 'questions' => [], 'send_delay_hours' => 48]);

        $this->hoursAfter(30);
        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->hoursAfter(48.5);
        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertQueued(SurveyInvitationMail::class, 1);
    }

    public function test_it_goes_at_the_hour_the_feedback_tab_says_even_with_a_delay_shorter_than_the_door_takes(): void
    {
        $this->holder('ada@example.com');
        // Older than the rule that refuses it: two hours, sooner than the
        // door's last scans can be in.
        EventSurvey::create(['event_id' => $this->event->id, 'questions' => [], 'send_delay_hours' => 2]);
        $this->asMember(Role::Manager);

        $shown = $this->getJson("/api/organizer/events/{$this->event->id}/survey")->json('sends_at');
        $this->assertSame($this->event->ends_at->copy()->addHours(16)->toIso8601String(), $shown);

        // Every hour, as the scheduler runs them, and the surveys first: the
        // door's record is written down by an earlier run than the one that
        // sends, whichever of the two runs first within the hour.
        $went = null;
        foreach (range(1, 24) as $hour) {
            $this->hoursAfter($hour);
            $this->artisan('surveys:send-due')->assertSuccessful();
            $this->artisan('disputes:record-completions')->assertSuccessful();

            if ($went === null && SurveyInvitation::count() > 0) {
                $went = now()->toIso8601String();
            }
        }

        $this->assertSame($shown, $went);
        Mail::assertQueued(SurveyInvitationMail::class, 1);
    }

    public function test_switched_off_for_the_night_or_for_the_organization_nobody_is_asked(): void
    {
        $this->holder('ada@example.com');
        $this->writtenDown();
        EventSurvey::create(['event_id' => $this->event->id, 'questions' => [], 'enabled' => false]);

        $other = $this->aNight(['title' => 'Highlife Sunday']);
        $this->holder('chidi@example.com', event: $other);
        $this->writtenDown($other);

        $this->org->forceFill(['surveys_enabled' => false])->save();

        $this->hoursAfter(19);
        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertNothingQueued();

        // The organization back on: the night switched off stays off.
        $this->org->forceFill(['surveys_enabled' => true])->save();
        $this->artisan('surveys:send-due')->assertSuccessful();

        Mail::assertQueued(SurveyInvitationMail::class, 1);
        Mail::assertQueued(SurveyInvitationMail::class, fn ($mail) => $mail->hasTo('chidi@example.com'));
    }

    public function test_a_cancelled_night_is_never_asked_about(): void
    {
        $this->holder('ada@example.com');
        $this->writtenDown();
        $this->event->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        $this->hoursAfter(19);
        $this->artisan('surveys:send-due')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_a_night_long_over_when_surveys_arrive_is_left_alone(): void
    {
        $this->holder('ada@example.com');
        $this->writtenDown();

        // The first run after a deploy, a month after this night.
        $this->hoursAfter(24 * 30);
        $this->artisan('surveys:send-due')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, SurveyInvitation::count());
    }

    public function test_two_runs_at_once_never_ask_anybody_twice(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        $this->writtenDown();
        $this->hoursAfter(19);

        $sender = app(SurveySender::class);
        $survey = $sender->start($this->event);

        // The second run arrives while the first is still emailing.
        $this->assertNull($sender->start($this->event));
        $this->assertSame(2, SurveyInvitation::count());

        // One of them claimed already: only the other is emailed, once.
        SurveyInvitation::where('email', 'ada@example.com')->update(['sent_at' => now()]);
        $this->assertSame(1, $sender->deliver($survey));
        $this->assertSame(0, $sender->deliver($survey));

        Mail::assertQueued(SurveyInvitationMail::class, 1);
        Mail::assertQueued(SurveyInvitationMail::class, fn ($mail) => $mail->hasTo('chidi@example.com'));
    }

    public function test_a_run_that_died_before_emailing_everybody_is_finished_by_the_next(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        $this->writtenDown();
        $this->hoursAfter(19);

        // Invitations written, and the process gone before any email.
        app(SurveySender::class)->start($this->event);

        $this->artisan('surveys:send-due')->assertSuccessful();

        Mail::assertQueued(SurveyInvitationMail::class, 2);
        $this->assertSame(0, SurveyInvitation::whereNull('sent_at')->count());
    }

    public function test_each_link_is_its_own_and_the_email_says_how_to_stop_them(): void
    {
        $this->holder('ada@example.com');
        $this->holder('chidi@example.com');
        $this->writtenDown();
        $this->hoursAfter(19);
        $this->artisan('surveys:send-due')->assertSuccessful();

        $tokens = SurveyInvitation::pluck('token');
        $this->assertCount(2, $tokens->unique());
        $tokens->each(fn (string $token) => $this->assertGreaterThanOrEqual(40, strlen($token)));

        $invitation = SurveyInvitation::where('email', 'ada@example.com')->sole();
        $preference = EmailPreference::forEmail('ada@example.com');
        $mail = new SurveyInvitationMail($invitation, $preference);

        $mail->assertHasSubject('How was Afro Fest?');
        $mail->assertSeeInHtml('/tickets/feedback/'.$invitation->token, false);
        $mail->assertSeeInHtml(route('unsubscribe', [$preference->token, 'kind' => 'marketing']), false);
        // Nobody else's link, and no ticket code, in somebody's email.
        $mail->assertDontSeeInHtml(SurveyInvitation::where('email', 'chidi@example.com')->value('token'));
        $this->assertStringContainsString('kind=marketing', $mail->headers()->text['List-Unsubscribe']);
    }
}
