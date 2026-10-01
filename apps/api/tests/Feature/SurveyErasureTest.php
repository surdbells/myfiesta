<?php

namespace Tests\Feature;

use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Exporter;
use App\Services\PersonalData\Subject;
use App\Services\Surveys\SurveyResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RunsSurveys;
use Tests\TestCase;

/**
 * A survey somebody was sent, and what they answered, in their export and
 * gone with an erasure — out of the organizer's figures too.
 */
class SurveyErasureTest extends TestCase
{
    use RefreshDatabase, RunsSurveys;

    public function test_an_export_carries_the_invitation_and_the_answers_but_never_the_link(): void
    {
        Mail::fake();
        $this->event = $this->aNight();
        $this->holder('Ada@Example.com');
        $survey = $this->sent();
        $this->answered($survey, 'ada@example.com', ['recommend' => 9, 'change' => 'More water at the bar']);
        $token = SurveyInvitation::sole()->token;

        $export = app(Exporter::class)->build(Subject::forEmail('ada@example.com'));

        $this->assertCount(1, $export['data']['survey_invitations']);
        $this->assertCount(1, $export['data']['survey_responses']);
        $this->assertStringContainsString('More water at the bar', json_encode($export));
        $this->assertStringNotContainsString($token, json_encode($export));
    }

    public function test_an_erasure_takes_the_invitation_and_the_answers_out_of_the_results(): void
    {
        Mail::fake();
        $this->event = $this->aNight();

        foreach (range(1, 6) as $n) {
            $this->holder("guest{$n}@example.com");
        }

        $survey = $this->sent();

        foreach (range(1, 6) as $n) {
            $this->answered($survey, "guest{$n}@example.com", ['recommend' => 10, 'change' => "Said by guest {$n}"]);
        }

        $before = app(SurveyResults::class)->summarise($survey->fresh());
        $this->assertSame(6, $before['responded']);

        app(Eraser::class)->erase(Subject::forEmail('GUEST1@example.com'));

        $this->assertSame(0, SurveyInvitation::where('email', 'guest1@example.com')->count());
        $this->assertSame(5, SurveyResponse::count());
        $this->assertSame(5, SurveyInvitation::count());

        $after = app(SurveyResults::class)->summarise($survey->fresh());
        $this->assertSame(5, $after['responded']);
        $this->assertNotContains('Said by guest 1', $after['questions'][5]['texts']);
    }
}
