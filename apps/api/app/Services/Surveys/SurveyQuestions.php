<?php

namespace App\Services\Surveys;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a survey may ask, and what an answer to it may be.
 *
 * Five kinds of question, each with a fixed shape of answer:
 *
 *   nps      0 to 10, "how likely are you to recommend"
 *   rating5  1 to 5
 *   single   one of the question's options
 *   multi    any of its options, at least one
 *   text     up to 2,000 characters
 *
 * Twelve at most. A survey is answered on a phone the morning after, and
 * each question past the first few loses people who would have finished.
 *
 * Answers are checked against the questions the night was sent with, never
 * the template as it is now: somebody answering on Tuesday a survey sent on
 * Sunday answers what they were asked.
 */
class SurveyQuestions
{
    public const TYPES = ['nps', 'rating5', 'single', 'multi', 'text'];

    public const MAX_QUESTIONS = 12;

    public const MAX_OPTIONS = 10;

    public const MAX_TEXT = 2000;

    /**
     * A template's questions as an organizer sent them, checked and tidied:
     * an id for every new question, options only where they mean something.
     *
     * @return list<array{id: string, type: string, label: string, options: list<string>, required: bool}>
     *
     * @throws ValidationException
     */
    public function fromInput(mixed $questions): array
    {
        Validator::make(['questions' => $questions], [
            'questions' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_QUESTIONS],
            'questions.*' => ['required', 'array'],
            'questions.*.id' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
            'questions.*.type' => ['required', Rule::in(self::TYPES)],
            'questions.*.label' => ['required', 'string', 'max:200'],
            'questions.*.options' => ['nullable', 'array', 'list', 'max:'.self::MAX_OPTIONS],
            'questions.*.options.*' => ['nullable', 'string', 'max:80'],
            'questions.*.required' => ['sometimes', 'boolean'],
        ], [
            'questions.required' => 'Add at least one question.',
            'questions.min' => 'Add at least one question.',
            'questions.max' => 'A survey can ask '.self::MAX_QUESTIONS.' questions at most.',
            'questions.*.label.required' => 'Write the question.',
            'questions.*.label.max' => 'Keep the question under 200 characters.',
            'questions.*.options.max' => 'Offer '.self::MAX_OPTIONS.' choices at most.',
            'questions.*.options.*.max' => 'Keep each choice under 80 characters.',
        ])->validate();

        $tidy = [];
        $seen = [];
        $errors = [];

        foreach (array_values($questions) as $index => $question) {
            $type = $question['type'];
            $id = $question['id'] ?? null;

            if ($id === null || $id === '') {
                // New questions are named here, so an edit that moves one
                // keeps its answers attached to it.
                do {
                    $id = 'q'.Str::lower(Str::random(7));
                } while (isset($seen[$id]));
            } elseif (isset($seen[$id])) {
                $errors["questions.$index.id"] = 'Two questions have the same id.';
            }

            $seen[$id] = true;

            $options = in_array($type, ['single', 'multi'], true)
                ? array_values(array_unique(array_filter(
                    array_map(fn ($option) => trim((string) $option), $question['options'] ?? []),
                    fn (string $option) => $option !== '',
                )))
                : [];

            if (in_array($type, ['single', 'multi'], true) && count($options) < 2) {
                $errors["questions.$index.options"] = 'Give people at least two different choices.';
            }

            $tidy[] = [
                'id' => $id,
                'type' => $type,
                'label' => trim($question['label']),
                'options' => $options,
                'required' => (bool) ($question['required'] ?? false),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $tidy;
    }

    /**
     * Somebody's answers, checked against what they were asked.
     *
     * An empty answer to an optional question is no answer, and is left out
     * rather than kept as an empty string, so the counts only count people who
     * said something. An answer to a question that was not asked is refused:
     * it can only come from a page built for another survey.
     *
     * @param  list<array{id: string, type: string, label: string, options: list<string>, required: bool}>  $questions
     * @return array<string, int|string|list<string>>
     *
     * @throws ValidationException
     */
    public function answers(array $questions, mixed $answers): array
    {
        if (! is_array($answers)) {
            throw ValidationException::withMessages(['answers' => 'There are no answers to send.']);
        }

        $asked = collect($questions)->keyBy('id');
        $unknown = array_diff(array_map('strval', array_keys($answers)), $asked->keys()->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => 'Those answers are for questions this survey does not ask. Open the link from your email again.']);
        }

        $kept = [];
        $errors = [];

        foreach ($questions as $question) {
            $id = $question['id'];
            $value = $this->blankToNull($answers[$id] ?? null);

            if ($value === null) {
                if ($question['required']) {
                    $errors["answers.$id"] = 'Please answer this one.';
                }

                continue;
            }

            [$accepted, $checked] = $this->check($question, $value);

            if (! $accepted) {
                $errors["answers.$id"] = $checked;

                continue;
            }

            $kept[$id] = $checked;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($kept === []) {
            throw ValidationException::withMessages(['answers' => 'Answer at least one question.']);
        }

        return $kept;
    }

    /**
     * One answer as it is kept, or why it cannot be.
     *
     * @param  array{id: string, type: string, label: string, options: list<string>, required: bool}  $question
     * @return array{0: bool, 1: int|string|list<string>} accepted, then the answer or the reason
     */
    private function check(array $question, mixed $value): array
    {
        $kept = match ($question['type']) {
            'nps' => $this->score($value, 0, 10),
            'rating5' => $this->score($value, 1, 5),
            'single' => is_string($value) && in_array($value, $question['options'], true) ? $value : null,
            'multi' => $this->choices($value, $question['options']),
            'text' => is_string($value) && mb_strlen(trim($value)) <= self::MAX_TEXT ? trim($value) : null,
            default => null,
        };

        if ($kept !== null) {
            return [true, $kept];
        }

        return [false, match ($question['type']) {
            'nps' => 'Choose a number from 0 to 10.',
            'rating5' => 'Choose from 1 to 5.',
            'single' => 'Choose one of the answers given.',
            'multi' => 'Choose from the answers given.',
            'text' => 'Keep it under '.number_format(self::MAX_TEXT).' characters.',
            default => 'This question cannot be answered.',
        }];
    }

    private function score(mixed $value, int $low, int $high): ?int
    {
        // A whole number only: 7.5 on a scale of whole numbers was not a
        // button anybody pressed.
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,2}$/', $value))) {
            $score = (int) $value;

            return $score >= $low && $score <= $high ? $score : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $options
     * @return list<string>|null
     */
    private function choices(mixed $value, array $options): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $choice) {
            if (! is_string($choice) || ! in_array($choice, $options, true)) {
                return null;
            }
        }

        // In the order the question offers them, once each.
        return array_values(array_intersect($options, $value));
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $value;
    }
}
