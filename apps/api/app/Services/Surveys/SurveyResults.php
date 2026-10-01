<?php

namespace App\Services\Surveys;

use App\Models\Event;
use App\Models\EventSurvey;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;

/**
 * What the people who came said, as an organizer is shown it.
 *
 * Counts, scores and what was typed, and never who said it: the addresses
 * stay on the invitations, which nothing here reads past a count.
 *
 * Nothing is broken down until five people have answered, and no question
 * until five have answered it. Below that, an organizer who knows who came
 * can tell whose "1 out of 5" it was, and an answer given without a name
 * should not be readable as one with a name.
 */
class SurveyResults
{
    public const MINIMUM = 5;

    public function __construct(
        private readonly EventSurveys $surveys,
        private readonly SurveyInsights $insights,
    ) {}

    /**
     * A night's results, with what to do about them.
     *
     * @return array<string, mixed>
     */
    public function for(Event $event, ?EventSurvey $survey): array
    {
        $summary = $this->summarise($survey);

        $summary['insights'] = $summary['enough'] && $survey !== null
            ? $this->insights->for($summary, $this->previous($event, $survey))
            : [];

        return $summary;
    }

    /**
     * The figures alone: how many were asked and answered, the recommend
     * score, and each question's.
     *
     * @return array{sent_at: ?string, invited: int, responded: int, response_rate: ?int, minimum: int, enough: bool, nps: ?array<string, int>, questions: list<array<string, mixed>>}
     */
    public function summarise(?EventSurvey $survey): array
    {
        $sent = $survey?->sent_at !== null;
        $questions = $this->surveys->questions($survey);

        $invited = $sent ? SurveyInvitation::query()->where('event_survey_id', $survey->id)->count() : 0;

        /** @var list<array<string, mixed>> $answers newest first */
        $answers = $sent
            ? SurveyResponse::query()
                ->where('event_survey_id', $survey->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('answers')
                ->all()
            : [];

        $responded = count($answers);
        $enough = $responded >= self::MINIMUM;

        $summaries = array_map(fn (array $question) => $this->question($question, $answers, $enough), $questions);

        $recommend = collect($questions)->firstWhere('type', 'nps');

        return [
            'sent_at' => $survey?->sent_at?->toIso8601String(),
            'invited' => $invited,
            'responded' => $responded,
            'response_rate' => $invited > 0 ? (int) round($responded / $invited * 100) : null,
            'minimum' => self::MINIMUM,
            'enough' => $enough,
            'nps' => $enough && $recommend !== null ? $this->nps($recommend['id'], $answers) : null,
            'questions' => $summaries,
        ];
    }

    /**
     * One question: how many answered it, and — once enough have — its
     * average, how the answers spread, or what was written.
     *
     * @param  array{id: string, type: string, label: string, options: list<string>, required: bool}  $question
     * @param  list<array<string, mixed>>  $answers
     * @return array<string, mixed>
     */
    private function question(array $question, array $answers, bool $enough): array
    {
        $given = array_values(array_filter(
            array_map(fn (array $answer) => $answer[$question['id']] ?? null, $answers),
            fn ($value) => $value !== null,
        ));

        $answered = count($given);
        $shown = $enough && $answered >= self::MINIMUM;

        $scored = in_array($question['type'], ['nps', 'rating5'], true);

        return [
            'id' => $question['id'],
            'type' => $question['type'],
            'label' => $question['label'],
            'answered' => $answered,
            'shown' => $shown,
            'average' => $shown && $scored ? round(array_sum(array_map('intval', $given)) / $answered, 1) : null,
            'distribution' => $shown && $question['type'] !== 'text' ? $this->distribution($question, $given) : null,
            // What was typed, newest first, and nothing else about who typed it.
            'texts' => $shown && $question['type'] === 'text' ? array_map('strval', $given) : null,
        ];
    }

    /**
     * How many gave each answer, every possible answer listed — a 0 beside
     * "1 out of 5" is worth seeing too.
     *
     * @param  array{id: string, type: string, label: string, options: list<string>, required: bool}  $question
     * @param  list<mixed>  $given
     * @return list<array{label: string, count: int}>
     */
    private function distribution(array $question, array $given): array
    {
        $labels = match ($question['type']) {
            'nps' => array_map('strval', range(0, 10)),
            'rating5' => array_map('strval', range(1, 5)),
            default => $question['options'],
        };

        $counts = array_fill_keys($labels, 0);

        foreach ($given as $value) {
            // A multiple choice is one answer with several choices in it.
            foreach ((array) $value as $choice) {
                $key = (string) $choice;

                if (array_key_exists($key, $counts)) {
                    $counts[$key]++;
                }
            }
        }

        return array_map(
            fn (string $label) => ['label' => $label, 'count' => $counts[$label]],
            array_keys($counts),
        );
    }

    /**
     * The recommend score: the share who would (9 or 10) less the share who
     * would not (0 to 6), from -100 to 100.
     *
     * @param  list<array<string, mixed>>  $answers
     * @return array{score: int, promoters: int, passives: int, detractors: int, answered: int}|null
     */
    private function nps(string $id, array $answers): ?array
    {
        $scores = array_values(array_filter(
            array_map(fn (array $answer) => isset($answer[$id]) ? (int) $answer[$id] : null, $answers),
            fn ($score) => $score !== null,
        ));

        $answered = count($scores);

        if ($answered < self::MINIMUM) {
            return null;
        }

        $promoters = count(array_filter($scores, fn (int $score) => $score >= 9));
        $detractors = count(array_filter($scores, fn (int $score) => $score <= 6));

        return [
            'score' => (int) round(($promoters - $detractors) / $answered * 100),
            'promoters' => $promoters,
            'passives' => $answered - $promoters - $detractors,
            'detractors' => $detractors,
            'answered' => $answered,
        ];
    }

    /**
     * The organization's last night before this one sent the same questions,
     * with enough answers to compare against, and its figures.
     *
     * @return array{title: string, summary: array<string, mixed>}|null
     */
    private function previous(Event $event, EventSurvey $survey): ?array
    {
        if ($survey->template_id === null) {
            return null;
        }

        $earlier = EventSurvey::query()
            ->join('events', 'events.id', '=', 'event_surveys.event_id')
            ->where('events.organization_id', $event->organization_id)
            ->where('events.starts_at', '<', $event->starts_at)
            ->where('event_surveys.template_id', $survey->template_id)
            ->where('event_surveys.id', '!=', $survey->id)
            ->whereNotNull('event_surveys.sent_at')
            ->orderByDesc('events.starts_at')
            ->limit(5)
            ->get(['event_surveys.*', 'events.title as event_title']);

        foreach ($earlier as $candidate) {
            $summary = $this->summarise($candidate);

            if ($summary['enough']) {
                return ['title' => (string) $candidate->getAttribute('event_title'), 'summary' => $summary];
            }
        }

        return null;
    }
}
