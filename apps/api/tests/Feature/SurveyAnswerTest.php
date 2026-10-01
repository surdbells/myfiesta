<?php

namespace Tests\Feature;

use App\Models\EventSurvey;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\SurveyTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\RunsSurveys;
use Tests\TestCase;

/**
 * Answering the survey from the email's link: the token is the whole
 * credential, the answers are checked against what was asked, and there is
 * one answer per link.
 */
class SurveyAnswerTest extends TestCase
{
    use RefreshDatabase, RunsSurveys;

    private EventSurvey $survey;

    private SurveyInvitation $invitation;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = $this->aNight();
        $this->holder('ada@example.com');
        $this->hoursAfter(19);

        $this->survey = $this->sent();
        $this->invitation = SurveyInvitation::sole();
    }

    private function answers(array $overrides = []): array
    {
        return array_merge([
            'recommend' => 9,
            'sound' => 4,
            'venue' => '5',
            'door' => null,
            'value' => '',
            'change' => '  More water at the bar.  ',
        ], $overrides);
    }

    public function test_the_link_shows_the_night_and_the_questions_and_nothing_about_whose_it_is(): void
    {
        $response = $this->getJson('/api/surveys/'.$this->invitation->token)
            ->assertOk()
            ->assertJsonPath('event.title', 'Afro Fest')
            ->assertJsonPath('event.organizer', 'Lagos Nights')
            ->assertJsonPath('answered', false)
            ->assertJsonCount(6, 'questions')
            ->assertJsonPath('questions.0.id', 'recommend')
            ->assertJsonPath('questions.0.type', 'nps');

        $this->assertStringNotContainsString('ada@example.com', $response->getContent());
    }

    public function test_a_link_nobody_was_sent_finds_nothing(): void
    {
        $this->getJson('/api/surveys/not-a-real-token')->assertNotFound();
        $this->postJson('/api/surveys/not-a-real-token', ['answers' => ['recommend' => 9]])->assertNotFound();
        $this->getJson('/api/surveys/'.str_repeat('a', 300))->assertNotFound();
    }

    public function test_an_answer_is_kept_once_as_it_was_given(): void
    {
        $this->postJson('/api/surveys/'.$this->invitation->token, ['answers' => $this->answers()])
            ->assertCreated()
            ->assertJsonPath('survey.answered', true);

        $kept = SurveyResponse::sole();
        // Blank answers to optional questions are no answer, not an empty one.
        $this->assertEquals(['recommend' => 9, 'sound' => 4, 'venue' => 5, 'change' => 'More water at the bar.'], $kept->answers);
        $this->assertNotNull($this->invitation->fresh()->responded_at);

        $this->postJson('/api/surveys/'.$this->invitation->token, ['answers' => $this->answers(['recommend' => 0])])
            ->assertStatus(409);

        $this->assertSame(1, SurveyResponse::count());
        $this->assertSame(9, SurveyResponse::sole()->answers['recommend']);
        $this->getJson('/api/surveys/'.$this->invitation->token)->assertJsonPath('answered', true);
    }

    public function test_answers_are_checked_against_what_was_asked(): void
    {
        $token = $this->invitation->token;

        $this->postJson("/api/surveys/{$token}", ['answers' => $this->answers(['recommend' => 11])])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.recommend');
        $this->postJson("/api/surveys/{$token}", ['answers' => $this->answers(['sound' => 0])])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.sound');
        $this->postJson("/api/surveys/{$token}", ['answers' => $this->answers(['sound' => 3.5])])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.sound');
        $this->postJson("/api/surveys/{$token}", ['answers' => $this->answers(['recommend' => null])])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.recommend');
        $this->postJson("/api/surveys/{$token}", ['answers' => $this->answers(['change' => str_repeat('a', 2001)])])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.change');
        $this->postJson("/api/surveys/{$token}", ['answers' => $this->answers(['favourite_dj' => 'Tems'])])
            ->assertUnprocessable()->assertJsonValidationErrors('answers');
        $this->postJson("/api/surveys/{$token}", ['answers' => 'great'])
            ->assertUnprocessable()->assertJsonValidationErrors('answers');

        $this->assertSame(0, SurveyResponse::count());
    }

    public function test_choices_must_be_ones_the_question_offered(): void
    {
        $this->survey->forceFill(['questions' => [
            ['id' => 'heard', 'type' => 'single', 'label' => 'How did you hear?', 'options' => ['Instagram', 'A friend'], 'required' => true],
            ['id' => 'liked', 'type' => 'multi', 'label' => 'What did you like?', 'options' => ['DJ', 'Food', 'Crowd'], 'required' => false],
        ]])->save();
        $token = $this->invitation->token;

        $this->postJson("/api/surveys/{$token}", ['answers' => ['heard' => 'TikTok']])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.heard');
        $this->postJson("/api/surveys/{$token}", ['answers' => ['heard' => 'A friend', 'liked' => ['Food', 'Parking']]])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.liked');

        $this->postJson("/api/surveys/{$token}", ['answers' => ['heard' => 'A friend', 'liked' => ['Crowd', 'DJ', 'DJ']]])
            ->assertCreated();

        // In the order offered, once each.
        $this->assertSame(['DJ', 'Crowd'], SurveyResponse::sole()->answers['liked']);
    }

    public function test_a_template_edited_after_the_night_was_sent_changes_nothing_that_was_asked(): void
    {
        SurveyTemplate::platformDefault()->forceFill(['questions' => [
            ['id' => 'something_else', 'type' => 'text', 'label' => 'Anything?', 'options' => [], 'required' => true],
        ]])->save();

        $this->getJson('/api/surveys/'.$this->invitation->token)
            ->assertJsonCount(6, 'questions')
            ->assertJsonPath('questions.0.id', 'recommend');

        $this->postJson('/api/surveys/'.$this->invitation->token, ['answers' => ['recommend' => 10]])->assertCreated();
    }

    public function test_the_survey_carries_what_the_contract_promises(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));
        $schemas = $spec['components']['schemas'];

        $body = $this->getJson('/api/surveys/'.$this->invitation->token)->assertOk()->json();

        foreach ($schemas['PublicSurvey']['required'] as $field) {
            $this->assertArrayHasKey($field, $body, "PublicSurvey.$field");
        }

        foreach ($schemas['SurveyQuestion']['required'] as $field) {
            $this->assertArrayHasKey($field, $body['questions'][0], "SurveyQuestion.$field");
        }
    }
}
