<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EventSurvey;
use App\Models\Organization;
use App\Services\Surveys\SurveyInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RunsSurveys;
use Tests\TestCase;

/**
 * What an organizer reads: how many answered, the recommend score, each
 * question's figures and what was written — never who wrote it, and nothing
 * broken down until five people have answered.
 */
class SurveyResultsTest extends TestCase
{
    use RefreshDatabase, RunsSurveys;

    private EventSurvey $survey;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = $this->aNight();

        foreach (range(1, 10) as $n) {
            $this->holder("guest{$n}@example.com");
        }

        $this->hoursAfter(19);
        $this->survey = $this->sent();
    }

    private function results()
    {
        return $this->getJson("/api/organizer/events/{$this->event->id}/survey/results");
    }

    public function test_under_five_answers_only_the_counts_are_shown(): void
    {
        foreach (range(1, 4) as $n) {
            $this->answered($this->survey, "guest{$n}@example.com", ['recommend' => 10, 'sound' => 1, 'change' => 'The sound was awful']);
        }

        $this->asMember(Role::Manager);

        $this->results()
            ->assertOk()
            ->assertJsonPath('invited', 10)
            ->assertJsonPath('responded', 4)
            ->assertJsonPath('response_rate', 40)
            ->assertJsonPath('enough', false)
            ->assertJsonPath('minimum', 5)
            ->assertJsonPath('nps', null)
            ->assertJsonPath('questions.0.answered', 4)
            ->assertJsonPath('questions.0.shown', false)
            ->assertJsonPath('questions.0.average', null)
            ->assertJsonPath('questions.0.distribution', null)
            ->assertJsonPath('questions.5.texts', null)
            ->assertJsonPath('insights', [])
            ->assertDontSee('awful');
    }

    public function test_from_five_answers_the_figures_appear_and_never_an_address(): void
    {
        $scores = [10, 9, 8, 6, 3];
        foreach ($scores as $index => $score) {
            $n = $index + 1;
            $this->answered($this->survey, "guest{$n}@example.com", [
                'recommend' => $score,
                'sound' => $n,
                'venue' => 4,
                // Only two said anything about the door: not enough to show.
                ...($n <= 2 ? ['door' => 5] : []),
                'change' => "Answer number {$n}",
            ]);
        }

        $this->asMember(Role::Marketing);
        $response = $this->results()->assertOk();

        $response
            ->assertJsonPath('responded', 5)
            ->assertJsonPath('response_rate', 50)
            ->assertJsonPath('enough', true)
            // Two promoters, one passive, two detractors: 40% - 40% = 0.
            ->assertJsonPath('nps.score', 0)
            ->assertJsonPath('nps.promoters', 2)
            ->assertJsonPath('nps.passives', 1)
            ->assertJsonPath('nps.detractors', 2)
            ->assertJsonPath('questions.0.average', 7.2)
            ->assertJsonPath('questions.1.id', 'sound')
            ->assertJsonPath('questions.1.average', 3)
            ->assertJsonPath('questions.1.distribution', [
                ['label' => '1', 'count' => 1], ['label' => '2', 'count' => 1], ['label' => '3', 'count' => 1],
                ['label' => '4', 'count' => 1], ['label' => '5', 'count' => 1],
            ])
            ->assertJsonPath('questions.3.id', 'door')
            ->assertJsonPath('questions.3.answered', 2)
            ->assertJsonPath('questions.3.shown', false)
            ->assertJsonPath('questions.3.average', null)
            ->assertJsonCount(5, 'questions.5.texts')
            ->assertJsonPath('questions.5.texts.0', 'Answer number 5');

        $this->assertCount(11, $response->json('questions.0.distribution'));
        $this->assertStringNotContainsString('@example.com', $response->getContent());
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_a_choice_question_counts_each_choice(): void
    {
        $this->survey->forceFill(['questions' => [
            ['id' => 'liked', 'type' => 'multi', 'label' => 'What did you like?', 'options' => ['DJ', 'Food', 'Crowd'], 'required' => false],
        ]])->save();

        foreach (range(1, 5) as $n) {
            $this->answered($this->survey, "guest{$n}@example.com", ['liked' => $n <= 3 ? ['DJ', 'Crowd'] : ['DJ']]);
        }

        $this->asMember(Role::Owner);

        $this->results()
            ->assertJsonPath('nps', null)
            ->assertJsonPath('questions.0.average', null)
            ->assertJsonPath('questions.0.distribution', [
                ['label' => 'DJ', 'count' => 5], ['label' => 'Food', 'count' => 0], ['label' => 'Crowd', 'count' => 3],
            ]);
    }

    public function test_the_insights_name_the_lowest_score_what_changed_and_what_people_kept_saying(): void
    {
        // The organization's last night, sent the same questions.
        $before = $this->aNight(['title' => 'Afro Fest Vol. 1', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subMonth()->addHours(5)]);
        foreach (range(1, 5) as $n) {
            $this->holder("old{$n}@example.com", event: $before);
        }
        $earlier = $this->sent($before);
        foreach (range(1, 5) as $n) {
            $this->answered($earlier, "old{$n}@example.com", ['recommend' => 10, 'door' => 5, 'sound' => 4]);
        }

        foreach (range(1, 5) as $n) {
            $this->answered($this->survey, "guest{$n}@example.com", [
                'recommend' => $n <= 2 ? 10 : 5,
                'door' => 2,
                'sound' => 4,
                'change' => match (true) {
                    $n <= 2 => 'The queue outside was far too long',
                    $n === 3 => 'The queue outside was far too long. Bring back the saxophone player!',
                    default => 'Bring back the saxophone player',
                },
            ]);
        }

        $this->asMember(Role::Manager);
        $insights = collect($this->results()->assertOk()->json('insights'));

        $lowest = $insights->firstWhere('kind', 'lowest');
        $this->assertSame('Lowest rated: “How was getting in at the door?”, 2.0 out of 5.', $lowest['title']);
        $this->assertSame('door', $lowest['tab']);

        $changes = $insights->where('kind', 'change')->pluck('title')->all();
        $this->assertContains('Recommend score fell from 100 to -20 since Afro Fest Vol. 1.', $changes);
        $this->assertContains('“How was getting in at the door?” fell from 5.0 to 2.0 since Afro Fest Vol. 1.', $changes);
        // Sound held steady, so it is not mentioned.
        $this->assertCount(2, $changes);

        $words = $insights->where('kind', 'words')->values();
        // One word for each thing people said: the queue, and not "outside",
        // "far" and "long" from the same three answers too; the saxophone,
        // and not "player" as well.
        $this->assertSame(['queue', 'saxophone'], $words->pluck('word')->all());
        $queue = $words->firstWhere('word', 'queue');
        $this->assertSame('3 people mentioned “queue”.', $queue['title']);
        $this->assertSame('door', $queue['tab']);
        $this->assertSame('Read what they wrote about it below.', $words->firstWhere('word', 'saxophone')['action']);
    }

    public function test_common_words_are_not_counted_as_something_people_kept_saying(): void
    {
        $words = app(SurveyInsights::class)->wordsIn("It was a great night and the DJ's set was amazing, but the bar didn't have water");

        $this->assertSame(['djs', 'set', 'bar', 'water'], $words);
    }

    public function test_only_the_people_who_may_write_to_the_attendees_see_what_they_said(): void
    {
        $this->asMember(Role::Door);
        $this->results()->assertForbidden();

        $this->asMember(Role::Finance);
        $this->results()->assertForbidden();

        $elsewhere = Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $this->asMember(Role::Owner, $elsewhere);
        $this->results()->assertForbidden();
        $this->getJson("/api/organizer/events/{$this->event->id}/survey")->assertForbidden();
    }
}
