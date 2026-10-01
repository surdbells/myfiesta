<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\RunsSurveys;
use Tests\TestCase;

/**
 * The console reads its survey screens through types written from
 * packages/contract, so every field the contract declares on them is
 * returned — as null or [] when there is nothing to say — and a field the
 * console relies on cannot quietly go missing.
 */
class SurveyContractTest extends TestCase
{
    use RefreshDatabase, RunsSurveys;

    /** @var array<string, array<string, mixed>> */
    private array $schemas;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->schemas = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'))['components']['schemas'];

        $this->event = $this->aNight();

        foreach (range(1, 6) as $n) {
            $this->holder("guest{$n}@example.com");
        }

        $this->hoursAfter(19);
        $survey = $this->sent();

        foreach (range(1, 6) as $n) {
            $this->answered($survey, "guest{$n}@example.com", ['recommend' => $n + 3, 'sound' => 2, 'change' => 'The queue outside was long']);
        }

        $this->asMember(Role::Manager);
    }

    public function test_a_nights_survey_and_its_results_carry_what_the_contract_promises(): void
    {
        $survey = $this->getJson("/api/organizer/events/{$this->event->id}/survey")->assertOk()->json();
        $this->assertCarries('EventSurvey', $survey);
        $this->assertCarries('SurveyQuestion', $survey['questions'][0]);
        $this->assertCarries('SurveyTemplateChoice', $survey['templates'][0]);

        $results = $this->getJson("/api/organizer/events/{$this->event->id}/survey/results")->assertOk()->json();
        $this->assertCarries('SurveyResults', $results);
        $this->assertCarries('SurveyQuestionResult', $results['questions'][0]);
        $this->assertNotEmpty($results['insights']);
        $this->assertCarries('SurveyInsight', $results['insights'][0]);
    }

    public function test_the_surveys_screen_and_the_templates_carry_what_the_contract_promises(): void
    {
        $overview = $this->getJson('/api/organizer/surveys')->assertOk()->json();
        $this->assertCarries('SurveyOverview', $overview);
        $this->assertNotEmpty($overview['recent']);

        foreach ($this->schemas['SurveyOverview']['properties']['recent']['items']['required'] as $field) {
            $this->assertArrayHasKey($field, $overview['recent'][0], "SurveyOverview.recent.$field");
        }

        $templates = $this->getJson('/api/organizer/survey-templates')->assertOk()->json();
        $this->assertCarries('SurveyTemplateList', $templates);
        $this->assertCarries('SurveyTemplate', $templates['data'][0]);
    }

    /** @param  array<string, mixed>  $body */
    private function assertCarries(string $schema, array $body): void
    {
        foreach ($this->schemas[$schema]['required'] as $field) {
            $this->assertArrayHasKey($field, $body, "$schema declares '$field' and the API does not return it.");
        }
    }
}
