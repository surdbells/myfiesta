<?php

namespace App\Services\Surveys;

/**
 * What the answers suggest doing, by rule rather than guesswork.
 *
 * Three rules, each something an organizer can check by reading the figures
 * beside it:
 *
 *   lowest  the 1-to-5 question that scored lowest, when it averaged under 4
 *   change  how the recommend score and each rating moved since the last
 *           night that sent the same questions
 *   words   a word at least three people used in what they wrote, common
 *           words left out, and one word for each thing they said
 *
 * Each says what to do next, and where in the console to do it. The
 * suggestions for myFiesta's own questions are written for them, by their
 * fixed ids (SurveyTemplateSeeder); an organizer's own get a general one.
 */
class SurveyInsights
{
    /** A rating averaging below this is worth doing something about. */
    public const LOW_RATING = 4.0;

    /** A rating moving this far, or the recommend score this many points. */
    public const RATING_CHANGE = 0.3;

    public const SCORE_CHANGE = 5;

    /** People who used a word before it is worth a mention. */
    public const REPEATED = 3;

    /**
     * What to do about each of the topics people talk about, and the tab of
     * the event where it is done.
     *
     * @var array<string, array{action: string, tab: ?string}>
     */
    private const ACTIONS = [
        'sound' => [
            'action' => 'Ask for a soundcheck at full volume, with somebody listening from the back of the room.',
            'tab' => null,
        ],
        'venue' => [
            'action' => 'Walk the room with the venue before the next night: the bar, the toilets, where people stand.',
            'tab' => null,
        ],
        'door' => [
            'action' => 'Open the doors earlier or put a second phone on the door. A door pass sets one up in a minute.',
            'tab' => 'door',
        ],
        'value' => [
            'action' => 'Say what the ticket includes on the event page, or add an early-bird tier for people who decide early.',
            'tab' => 'tickets',
        ],
    ];

    /** @var array<string, list<string>> words that point at each topic */
    private const TOPIC_WORDS = [
        'door' => ['queue', 'queues', 'line', 'lines', 'lineup', 'wait', 'waiting', 'waited', 'entry', 'entrance', 'door', 'doors', 'security', 'scan', 'scanning', 'outside'],
        'sound' => ['sound', 'audio', 'loud', 'quiet', 'bass', 'mic', 'microphone', 'speakers', 'volume', 'music'],
        'value' => ['price', 'prices', 'pricey', 'expensive', 'cost', 'costly', 'money', 'fees', 'cheaper'],
        'venue' => ['bar', 'bars', 'drinks', 'drink', 'water', 'toilet', 'toilets', 'bathroom', 'bathrooms', 'washroom', 'washrooms', 'hot', 'cold', 'crowded', 'packed', 'space', 'room', 'venue', 'parking', 'coat', 'seats', 'seating'],
    ];

    /** Words that say nothing on their own about what to change. */
    private const STOPWORDS = [
        'about', 'after', 'again', 'all', 'also', 'always', 'amazing', 'and', 'another', 'any', 'are', 'around', 'awesome',
        'back', 'because', 'been', 'before', 'being', 'best', 'better', 'bit', 'both', 'bring', 'but', 'came', 'can', 'cant', 'come', 'could', 'did', 'didnt',
        'does', 'doesnt', 'dont', 'down', 'during', 'each', 'enough', 'especially', 'even', 'event', 'ever', 'every',
        'everyone', 'everything', 'excellent', 'experience', 'fantastic', 'far', 'felt', 'few', 'fine', 'for', 'from', 'fun', 'get',
        'getting', 'give', 'going', 'good', 'got', 'great', 'had', 'has', 'have', 'having', 'here', 'how', 'into', 'its',
        'just', 'keep', 'kind', 'know', 'last', 'less', 'like', 'liked', 'little', 'lot', 'lots', 'love', 'loved', 'made', 'make',
        'many', 'maybe', 'more', 'most', 'much', 'need', 'needs', 'next', 'nice', 'night', 'nothing', 'now', 'off', 'once',
        'one', 'only', 'other', 'our', 'out', 'over', 'overall', 'people', 'perfect', 'please', 'pretty', 'quite', 'really',
        'same', 'should', 'some', 'something', 'still', 'such', 'super', 'sure', 'than', 'thank', 'thanks', 'that', 'the', 'their',
        'them', 'then', 'there', 'these', 'they', 'thing', 'things', 'think', 'this', 'those', 'though', 'time', 'times',
        'too', 'two', 'very', 'want', 'was', 'way', 'well', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why',
        'will', 'with', 'would', 'wonderful', 'yes', 'you', 'your',
    ];

    /**
     * @param  array<string, mixed>  $summary  this night's figures (SurveyResults::summarise)
     * @param  array{title: string, summary: array<string, mixed>}|null  $previous
     * @return list<array{kind: string, tone: string, title: string, action: string, tab: ?string, word: ?string}>
     */
    public function for(array $summary, ?array $previous): array
    {
        return array_values(array_filter([
            $this->lowest($summary),
            ...$this->changes($summary, $previous),
            ...$this->words($summary),
        ]));
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{kind: string, tone: string, title: string, action: string, tab: ?string, word: ?string}|null
     */
    private function lowest(array $summary): ?array
    {
        $rated = collect($summary['questions'])
            ->filter(fn (array $question) => $question['type'] === 'rating5' && $question['average'] !== null)
            ->sortBy('average');

        $lowest = $rated->first();

        if ($lowest === null || $lowest['average'] >= self::LOW_RATING) {
            return null;
        }

        $advice = $this->adviceFor($lowest['id']);

        return [
            'kind' => 'lowest',
            'tone' => 'warning',
            'title' => sprintf('Lowest rated: “%s”, %s out of 5.', $lowest['label'], $this->rating($lowest['average'])),
            'action' => $advice['action'],
            'tab' => $advice['tab'],
            'word' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array{title: string, summary: array<string, mixed>}|null  $previous
     * @return list<array{kind: string, tone: string, title: string, action: string, tab: ?string, word: ?string}>
     */
    private function changes(array $summary, ?array $previous): array
    {
        if ($previous === null) {
            return [];
        }

        $before = $previous['summary'];
        $since = "since {$previous['title']}";
        $found = [];

        if (($summary['nps'] ?? null) !== null && ($before['nps'] ?? null) !== null) {
            $now = $summary['nps']['score'];
            $then = $before['nps']['score'];

            if (abs($now - $then) >= self::SCORE_CHANGE) {
                $found[] = [
                    'kind' => 'change',
                    'tone' => $now > $then ? 'good' : 'warning',
                    'title' => sprintf('Recommend score %s from %d to %d %s.', $now > $then ? 'rose' : 'fell', $then, $now, $since),
                    'action' => $now > $then
                        ? 'Keep what you changed, and say so in your next announcement.'
                        : 'Look at what changed between the two nights: the lineup, the room, the price, the door.',
                    'tab' => null,
                    'word' => null,
                    'size' => abs($now - $then) / 20,
                ];
            }
        }

        $earlier = collect($before['questions'])->keyBy('id');

        foreach ($summary['questions'] as $question) {
            $then = $earlier->get($question['id']);

            if ($question['type'] !== 'rating5' || $question['average'] === null || ($then['average'] ?? null) === null) {
                continue;
            }

            $moved = round($question['average'] - $then['average'], 1);

            if (abs($moved) < self::RATING_CHANGE) {
                continue;
            }

            $advice = $this->adviceFor($question['id']);

            $found[] = [
                'kind' => 'change',
                'tone' => $moved > 0 ? 'good' : 'warning',
                'title' => sprintf('“%s” %s from %s to %s %s.', $question['label'], $moved > 0 ? 'rose' : 'fell', $this->rating($then['average']), $this->rating($question['average']), $since),
                'action' => $moved > 0 ? 'Whatever changed there worked. Keep it for the next night.' : $advice['action'],
                'tab' => $moved > 0 ? null : $advice['tab'],
                'word' => null,
                'size' => abs($moved),
            ];
        }

        // The three biggest moves; a page of small ones buries the one that matters.
        usort($found, fn (array $a, array $b) => $b['size'] <=> $a['size']);

        return array_map(function (array $insight) {
            unset($insight['size']);

            return $insight;
        }, array_slice($found, 0, 3));
    }

    /**
     * Words people kept using, counted once per person.
     *
     * Three people writing "the queue outside was far too long" are one
     * complaint, not four, so a word is only another insight when it says
     * something new: one per topic, the word that points at it first; and a
     * word on no topic only when somebody used it who is not behind a word
     * already chosen. Among words used equally often, the one that says most
     * (telling).
     *
     * @param  array<string, mixed>  $summary
     * @return list<array{kind: string, tone: string, title: string, action: string, tab: ?string, word: ?string}>
     */
    private function words(array $summary): array
    {
        $people = [];

        foreach ($summary['questions'] as $question) {
            foreach ($question['texts'] ?? [] as $index => $text) {
                $people[$question['id'].':'.$index] = $text;
            }
        }

        /** @var array<string, list<string>> $usedIn the answers each word appears in */
        $usedIn = [];

        foreach ($people as $answer => $text) {
            foreach (array_unique($this->wordsIn($text)) as $word) {
                $usedIn[$word][] = $answer;
            }
        }

        $candidates = collect($usedIn)
            ->filter(fn (array $answers) => count($answers) >= self::REPEATED)
            ->map(fn (array $answers, $word) => ['word' => (string) $word, 'answers' => $answers, 'topic' => $this->topicOf((string) $word)])
            ->sort(fn (array $a, array $b) => [$b['topic'] !== null, count($b['answers']), $this->telling($b)]
                <=> [$a['topic'] !== null, count($a['answers']), $this->telling($a)])
            ->values();

        $found = [];
        $topics = [];
        $chosen = [];

        foreach ($candidates as $candidate) {
            if (count($found) === 3) {
                break;
            }

            if ($candidate['topic'] !== null) {
                if (isset($topics[$candidate['topic']])) {
                    continue;
                }

                $topics[$candidate['topic']] = true;
            } elseif (collect($chosen)->contains(fn (array $answers) => array_diff($candidate['answers'], $answers) === [])) {
                continue;
            }

            $chosen[] = $candidate['answers'];
            $advice = $candidate['topic'] !== null ? self::ACTIONS[$candidate['topic']] : null;

            $found[] = [
                'kind' => 'words',
                'tone' => 'neutral',
                'title' => sprintf('%d people mentioned “%s”.', count($candidate['answers']), $candidate['word']),
                'action' => $advice['action'] ?? 'Read what they wrote about it below.',
                'tab' => $advice['tab'] ?? null,
                'word' => $candidate['word'],
            ];
        }

        return $found;
    }

    /** @return list<string> */
    public function wordsIn(string $text): array
    {
        $text = mb_strtolower(str_replace(['’', "'"], '', $text));

        preg_match_all('/\p{L}{3,}/u', $text, $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $word) => ! in_array($word, self::STOPWORDS, true),
        ));
    }

    /**
     * How much a word says, to break a tie between words used equally often:
     * a topic's own words in the order TOPIC_WORDS lists them, "queue"
     * before "outside"; any other, the longer, which is more often the one
     * that names the thing ("saxophone" before "player").
     *
     * @param  array{word: string, topic: ?string}  $candidate
     */
    private function telling(array $candidate): int
    {
        if ($candidate['topic'] !== null) {
            return -(int) array_search($candidate['word'], self::TOPIC_WORDS[$candidate['topic']], true);
        }

        return mb_strlen($candidate['word']);
    }

    private function topicOf(string $word): ?string
    {
        foreach (self::TOPIC_WORDS as $topic => $words) {
            if (in_array($word, $words, true)) {
                return $topic;
            }
        }

        return null;
    }

    /** @return array{action: string, tab: ?string} */
    private function adviceFor(string $questionId): array
    {
        return self::ACTIONS[$questionId] ?? [
            'action' => 'Read what people wrote below, and ask the team what they saw on the night.',
            'tab' => null,
        ];
    }

    private function rating(float $average): string
    {
        return number_format($average, 1);
    }
}
