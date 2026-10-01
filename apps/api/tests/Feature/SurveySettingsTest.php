<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Mail\SurveyInvitationMail;
use App\Models\EventSurvey;
use App\Models\Organization;
use App\Models\SurveyInvitation;
use App\Models\SurveyTemplate;
use App\Models\User;
use App\Services\Impersonation\Impersonation;
use App\Services\Surveys\SurveySender;
use Database\Seeders\SurveyTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RunsSurveys;
use Tests\TestCase;

/**
 * The organizer's side: their own surveys, which one a night sends, when,
 * whether surveys go at all, and sending one now.
 */
class SurveySettingsTest extends TestCase
{
    use RefreshDatabase, RunsSurveys;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = $this->aNight();
    }

    /** @return list<array<string, mixed>> */
    private function questions(int $count): array
    {
        return array_map(fn (int $n) => ['type' => 'rating5', 'label' => "Question {$n}"], range(1, $count));
    }

    public function test_myfiestas_own_survey_is_there_for_every_organization(): void
    {
        $this->asMember(Role::Manager);

        $this->getJson('/api/organizer/survey-templates')
            ->assertOk()
            ->assertJsonPath('data.0.name', SurveyTemplateSeeder::NAME)
            ->assertJsonPath('data.0.platform', true)
            ->assertJsonCount(6, 'data.0.questions')
            ->assertJsonPath('max_questions', 12);

        // Installed once, however often the seeder runs.
        (new SurveyTemplateSeeder)->run();
        $this->assertSame(1, SurveyTemplate::whereNull('organization_id')->count());
    }

    public function test_an_organizer_writes_a_survey_of_up_to_twelve_questions(): void
    {
        $this->asMember(Role::Marketing);

        $created = $this->postJson('/api/organizer/survey-templates', [
            'name' => 'Short and sweet',
            'questions' => [
                ['type' => 'nps', 'label' => 'Would you bring a friend?', 'required' => true],
                ['type' => 'single', 'label' => 'How did you hear?', 'options' => ['Instagram', ' A friend ', 'Instagram', '']],
                ['type' => 'text', 'label' => 'Anything else?', 'options' => ['ignored']],
            ],
        ])->assertCreated();

        $questions = $created->json('data.questions');
        $this->assertCount(3, $questions);
        $this->assertSame(['Instagram', 'A friend'], $questions[1]['options']);
        $this->assertSame([], $questions[2]['options']);
        $this->assertTrue($questions[0]['required']);
        $this->assertFalse($questions[1]['required']);
        // Every question named, so an edit that moves one keeps its answers with it.
        $this->assertCount(3, array_unique(array_column($questions, 'id')));

        $this->postJson('/api/organizer/survey-templates', ['name' => 'Too long', 'questions' => $this->questions(13)])
            ->assertUnprocessable()->assertJsonValidationErrors('questions');
        $this->postJson('/api/organizer/survey-templates', ['name' => 'One choice', 'questions' => [
            ['type' => 'multi', 'label' => 'Pick', 'options' => ['Only one']],
        ]])->assertUnprocessable()->assertJsonValidationErrors('questions.0.options');
        $this->postJson('/api/organizer/survey-templates', ['name' => 'Odd', 'questions' => [
            ['type' => 'slider', 'label' => 'Slide'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('questions.0.type');
        $this->postJson('/api/organizer/survey-templates', ['name' => '', 'questions' => $this->questions(12)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->postJson('/api/organizer/survey-templates', ['name' => 'Twelve', 'questions' => $this->questions(12)])->assertCreated();
    }

    public function test_only_the_organizations_own_surveys_can_be_changed(): void
    {
        $elsewhere = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $theirs = SurveyTemplate::create(['organization_id' => $elsewhere->id, 'name' => 'Theirs', 'questions' => []]);
        $platform = SurveyTemplate::platformDefault();

        $this->asMember(Role::Manager);
        $body = ['name' => 'Mine now', 'questions' => $this->questions(1)];

        $this->putJson("/api/organizer/survey-templates/{$theirs->id}", $body)->assertNotFound();
        $this->putJson("/api/organizer/survey-templates/{$platform->id}", $body)->assertNotFound();
        $this->deleteJson("/api/organizer/survey-templates/{$platform->id}")->assertNotFound();
        $this->putJson('/api/organizer/survey-templates/not-a-uuid', $body)->assertNotFound();

        $mine = $this->postJson('/api/organizer/survey-templates', ['name' => 'Mine', 'questions' => $this->questions(2)])->json('data');
        $this->putJson("/api/organizer/survey-templates/{$mine['id']}", $body)->assertOk()->assertJsonPath('data.name', 'Mine now');

        // Door staff and finance do not write to attendees.
        $this->asMember(Role::Door);
        $this->getJson('/api/organizer/survey-templates')->assertForbidden();
        $this->asMember(Role::Finance);
        $this->postJson('/api/organizer/survey-templates', $body)->assertForbidden();
    }

    public function test_a_night_chooses_its_survey_its_delay_or_none_at_all(): void
    {
        $template = SurveyTemplate::create(['organization_id' => $this->org->id, 'name' => 'Ours', 'questions' => [
            ['id' => 'q1', 'type' => 'nps', 'label' => 'Recommend?', 'options' => [], 'required' => true],
        ]]);
        $this->asMember(Role::Manager);
        $url = "/api/organizer/events/{$this->event->id}/survey";

        // Nothing chosen yet: on, myFiesta's own, 18 hours after the end.
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('organization_enabled', true)
            ->assertJsonPath('template.platform', true)
            ->assertJsonPath('send_delay_hours', 18)
            ->assertJsonPath('sends_at', $this->event->ends_at->copy()->addHours(18)->toIso8601String())
            ->assertJsonPath('sent_at', null)
            ->assertJsonPath('stopped_because', null)
            ->assertJsonPath('can_send_now', false)
            ->assertJsonCount(2, 'templates');
        $this->assertSame(0, EventSurvey::count());

        $this->putJson($url, ['template_id' => $template->id, 'send_delay_hours' => 24])
            ->assertOk()
            ->assertJsonPath('template.name', 'Ours')
            ->assertJsonPath('send_delay_hours', 24)
            ->assertJsonCount(1, 'questions');

        $this->putJson($url, ['send_delay_hours' => 0])->assertUnprocessable();
        // Sooner than the door's final count (3 + 12 hours) and an hour for
        // the run that writes it down: it could not go when it said it would.
        $this->putJson($url, ['send_delay_hours' => 15])
            ->assertUnprocessable()
            ->assertJsonPath('errors.send_delay_hours.0', "Send it at least 16 hours after the event ends: the door's last scans can take that long to come in.");
        $this->putJson($url, ['send_delay_hours' => 169])->assertUnprocessable();
        $elsewhere = SurveyTemplate::create(['organization_id' => Organization::create(['name' => 'E', 'slug' => 'e'])->id, 'name' => 'E', 'questions' => []]);
        $this->putJson($url, ['template_id' => $elsewhere->id])->assertUnprocessable();

        $this->putJson($url, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('stopped_because', 'The survey is switched off for this event.');

        // Removing the survey a night chose sends myFiesta's instead.
        $this->putJson($url, ['enabled' => true]);
        $this->deleteJson("/api/organizer/survey-templates/{$template->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Removed “Ours”. The 1 night that was going to send it sends myFiesta’s survey instead.');
        $this->getJson($url)->assertJsonPath('template.platform', true)->assertJsonCount(6, 'questions');
    }

    public function test_the_organization_switches_surveys_off_for_every_night(): void
    {
        $this->asMember(Role::Owner);

        $this->putJson('/api/organizer/surveys/settings', ['surveys_enabled' => false])
            ->assertOk()
            ->assertJsonPath('surveys_enabled', false);

        $this->assertFalse((bool) $this->org->fresh()->surveys_enabled);
        $this->getJson("/api/organizer/events/{$this->event->id}/survey")
            ->assertJsonPath('organization_enabled', false)
            ->assertJsonPath('stopped_because', 'Surveys are switched off for your organization.');
        $this->getJson('/api/organizer/surveys')->assertOk()->assertJsonPath('surveys_enabled', false);
    }

    public function test_sending_now_asks_the_people_who_came_once(): void
    {
        $this->holder('ada@example.com');
        $user = $this->asMember(Role::Manager);
        $send = "/api/organizer/events/{$this->event->id}/survey/send";

        $this->postJson($send)->assertUnprocessable()->assertJsonPath('message', 'The night is not over yet. Send the survey once it has ended.');

        // Over, but the door's last scans are not in: sent now, it would ask
        // the people who never came.
        $this->hoursAfter(1);
        $this->getJson("/api/organizer/events/{$this->event->id}/survey")
            ->assertJsonPath('can_send_now', false)
            ->assertJsonPath('final_count_at', $this->event->ends_at->copy()->addHours(15)->toIso8601String());
        $from = $this->event->ends_at->copy()->addHours(15)->timezone('America/Toronto')->format('D j M, g:ia');
        $this->postJson($send)
            ->assertUnprocessable()
            ->assertJsonPath('message', "The door's last scans are still coming in, so it cannot go yet. You can send it from {$from}.");
        Mail::assertNothingQueued();

        $this->hoursAfter(15);
        $this->writtenDown();
        $this->getJson("/api/organizer/events/{$this->event->id}/survey")->assertJsonPath('can_send_now', true);

        $this->postJson($send)
            ->assertOk()
            ->assertJsonPath('invited', 1)
            ->assertJsonPath('message', 'Sent to 1 person.')
            ->assertJsonPath('survey.can_send_now', false);

        Mail::assertQueued(SurveyInvitationMail::class, 1);
        $this->assertSame($user->id, EventSurvey::sole()->sent_by);

        $this->postJson($send)->assertStatus(409);
        $this->putJson("/api/organizer/events/{$this->event->id}/survey", ['enabled' => false])->assertStatus(409);

        // The hourly run, later, finds it gone.
        $this->writtenDown();
        $this->hoursAfter(19);
        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertQueued(SurveyInvitationMail::class, 1);
        $this->assertSame(1, SurveyInvitation::count());

        $this->getJson('/api/organizer/surveys')
            ->assertJsonPath('recent.0.event_id', $this->event->id)
            ->assertJsonPath('recent.0.invited', 1)
            ->assertJsonPath('recent.0.nps', null);
    }

    public function test_a_survey_switched_off_cannot_be_sent_now(): void
    {
        $this->holder('ada@example.com');
        EventSurvey::create(['event_id' => $this->event->id, 'questions' => [], 'enabled' => false]);
        $this->asMember(Role::Manager);
        $this->hoursAfter(1);

        $this->postJson("/api/organizer/events/{$this->event->id}/survey/send")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The survey is switched off for this event.');

        Mail::assertNothingQueued();
    }

    public function test_the_tab_says_what_is_holding_a_finished_night_back(): void
    {
        $this->holder('ada@example.com');
        $this->asMember(Role::Manager);
        $url = "/api/organizer/events/{$this->event->id}/survey";

        // Due, but the door's final count is late: it says so, rather than
        // "Goes" at an hour already gone.
        $this->hoursAfter(19);
        $this->getJson($url)
            ->assertJsonPath('stopped_because', 'Waiting for the door’s final count. It goes on the hourly run after that.')
            ->assertJsonPath('can_send_now', false)
            ->assertJsonPath('due_now', false);

        // A night that sold nothing is never counted, and has nobody to ask.
        $empty = $this->aNight(['title' => 'Quiet Tuesday', 'starts_at' => $this->event->starts_at, 'ends_at' => $this->event->ends_at]);
        $this->getJson("/api/organizer/events/{$empty->id}/survey")
            ->assertJsonPath('stopped_because', 'Nobody held a ticket to this event, so there is nobody to ask.');

        $this->writtenDown();
        $this->getJson($url)
            ->assertJsonPath('stopped_because', null)
            ->assertJsonPath('due_now', true)
            ->assertJsonPath('can_send_now', true);
    }

    public function test_a_night_more_than_a_week_gone_is_not_asked_about_even_by_hand(): void
    {
        $this->holder('ada@example.com');
        $this->writtenDown();
        $this->asMember(Role::Manager);

        // A week after it was due: the hourly run has let it go, and the tab says so.
        $this->hoursAfter(18 + 24 * 7);
        $this->getJson("/api/organizer/events/{$this->event->id}/survey")
            ->assertJsonPath('stopped_because', 'This event ended too long ago to ask about. People are asked within a week, while they still remember the night.')
            ->assertJsonPath('can_send_now', false)
            ->assertJsonPath('due_now', false);

        $this->postJson("/api/organizer/events/{$this->event->id}/survey/send")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This event ended too long ago to ask about. People are asked within a week, while they still remember the night.');

        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertNothingQueued();
        $this->assertSame(0, SurveyInvitation::count());
    }

    public function test_switching_a_finished_night_back_on_says_it_goes_within_the_hour(): void
    {
        $this->holder('ada@example.com');
        $this->writtenDown();
        EventSurvey::create(['event_id' => $this->event->id, 'questions' => [], 'enabled' => false]);
        $this->asMember(Role::Owner);
        $this->hoursAfter(40);

        // Off, but due: switching it on sends it on the next run, so the tab asks first.
        $this->getJson("/api/organizer/events/{$this->event->id}/survey")
            ->assertJsonPath('stopped_because', 'The survey is switched off for this event.')
            ->assertJsonPath('due_now', true);

        // The organization's switch counts the nights it would send.
        $this->putJson("/api/organizer/events/{$this->event->id}/survey", ['enabled' => true])->assertOk();
        $this->putJson('/api/organizer/surveys/settings', ['surveys_enabled' => false])->assertOk();
        $this->getJson('/api/organizer/surveys')->assertJsonPath('nights_due_now', 1);

        $this->putJson('/api/organizer/surveys/settings', ['surveys_enabled' => true])
            ->assertOk()
            ->assertJsonPath('nights_due_now', 1)
            ->assertJsonPath('message', 'Surveys are on. 1 event that has finished is asked about within the hour.');

        $this->artisan('surveys:send-due')->assertSuccessful();
        Mail::assertQueued(SurveyInvitationMail::class, 1);
        $this->getJson('/api/organizer/surveys')->assertJsonPath('nights_due_now', 0);
    }

    public function test_a_change_that_arrives_as_the_hourly_run_sends_it_is_refused(): void
    {
        $this->holder('ada@example.com');
        $platform = SurveyTemplate::platformDefault();
        $ours = SurveyTemplate::create(['organization_id' => $this->org->id, 'name' => 'Ours', 'questions' => [
            ['id' => 'other', 'type' => 'text', 'label' => 'Anything else?', 'options' => [], 'required' => false],
        ]]);
        EventSurvey::create(['event_id' => $this->event->id, 'questions' => []]);
        $this->asMember(Role::Manager);

        // The hourly run sends it between the controller's first look and its write.
        $sent = false;
        EventSurvey::retrieved(function () use (&$sent) {
            if (! $sent) {
                $sent = true;
                app(SurveySender::class)->start($this->event);
            }
        });

        $this->putJson("/api/organizer/events/{$this->event->id}/survey", ['template_id' => $ours->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This survey has already gone out, so it cannot be changed.');

        // What people were asked stays what they were asked.
        $survey = EventSurvey::sole();
        $this->assertNotNull($survey->sent_at);
        $this->assertSame($platform->id, $survey->template_id);
        $this->assertSame(array_column($platform->questions, 'id'), array_column($survey->questions, 'id'));
    }

    public function test_staff_acting_as_the_organization_cannot_send_it_now(): void
    {
        $this->holder('ada@example.com');
        $this->writtenDown();
        $this->asMember(Role::Owner);
        $support = User::factory()->create(['platform_role' => PlatformRole::Support, 'email_verified_at' => now()]);
        $this->hoursAfter(16);

        $code = app(Impersonation::class)->start($this->org, $support, 'Ticket #77: survey questions')['code'];
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/impersonation/exchange', ['code' => $code])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        // An email in the organization's name, which cannot be called back.
        $this->withToken($token)
            ->withHeader('X-Organization', $this->org->id)
            ->postJson("/api/organizer/events/{$this->event->id}/survey/send")
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'cannot be called back'));

        Mail::assertNothingQueued();
        $this->assertSame(0, SurveyInvitation::count());
    }
}
