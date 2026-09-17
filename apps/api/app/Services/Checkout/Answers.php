<?php

namespace App\Services\Checkout;

use App\Exceptions\CheckoutException;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\OrderAnswer;
use Illuminate\Support\Collection;

/**
 * What the buyer was asked, and what they said.
 *
 * Two shapes arrive from a checkout. Answers for the order — how did you hear
 * about this — and answers about each person on it: the name for the door, a
 * phone number for a table, a dietary requirement.
 *
 * Everything here is checked against the questions as they are in the database
 * right now, never against what the page happened to render. A form is markup
 * a client controls; what must be answered and what an answer may say are the
 * server's to decide, exactly as prices are.
 *
 * The one deliberate leniency: an answer to a question that no longer exists
 * is dropped rather than refused. An organizer removing a question while
 * somebody is on the checkout page should not turn that person's purchase into
 * an error they cannot act on.
 */
class Answers
{
    /** Longest a typed answer may be. Enough for an address, short of an essay. */
    public const MAX_TEXT = 500;

    /**
     * Check a checkout's answers, and hand back what should be stored.
     *
     * Runs before any stock is held: a refusal here costs the buyer a message
     * and nothing else, and a hold taken for an order that was never going to
     * be created is stock nobody can buy for twenty minutes.
     *
     * @param  array<string, mixed>  $answers  question id => value, for the order
     * @param  list<array{ticket_type_id: string, answers?: array<string, mixed>}>  $attendees
     * @param  array<string, int>  $quantities  ticket type id => how many
     * @return array{
     *     order: array<string, list<mixed>>,
     *     attendees: list<array{ticket_type_id: string, index: int, answers: array<string, list<mixed>>}>
     * }
     */
    public function check(Event $event, array $answers, array $attendees, array $quantities): array
    {
        $questions = $event->questions()->get();

        $forOrder = $questions->where('per_attendee', false);
        $forEach = $questions->where('per_attendee', true);

        $checkedOrder = $this->answersTo($forOrder, $answers, 'this order');

        $this->assertAnswered($forOrder, $checkedOrder, null);

        if ($attendees === []) {
            // Nobody was described. Allowed only when nothing must be asked of
            // each person — an event with no per-person questions, or none of
            // them required.
            $this->assertNothingRequired($forEach);

            return ['order' => $checkedOrder, 'attendees' => []];
        }

        return [
            'order' => $checkedOrder,
            'attendees' => $this->checkAttendees($forEach, $attendees, $quantities),
        ];
    }

    /**
     * Write them, once the order and its lines exist.
     *
     * Called inside the transaction that creates the order, so an order can
     * never exist without the answers it was placed with.
     *
     * @param  array{order: array<string, list<mixed>>, attendees: list<array{ticket_type_id: string, index: int, answers: array<string, list<mixed>>}>}  $checked
     */
    public function store(Order $order, array $checked): void
    {
        foreach ($checked['order'] as $questionId => $value) {
            OrderAnswer::create([
                'order_id' => $order->id,
                'event_question_id' => $questionId,
                'value' => $value,
            ]);
        }

        if ($checked['attendees'] === []) {
            return;
        }

        $lines = $order->lines()->get()->keyBy('ticket_type_id');

        foreach ($checked['attendees'] as $attendee) {
            $line = $lines->get($attendee['ticket_type_id']);

            if ($line === null) {
                continue;
            }

            foreach ($attendee['answers'] as $questionId => $value) {
                OrderAnswer::create([
                    'order_id' => $order->id,
                    'event_question_id' => $questionId,
                    'order_line_id' => $line->id,
                    'attendee_index' => $attendee['index'],
                    'value' => $value,
                ]);
            }
        }
    }

    /**
     * One person per ticket, and the right number of them.
     *
     * The index is the position within their own ticket type, which is the
     * same order tickets are minted in — that is what later lets a scanned
     * code find the answers given for the person holding it.
     *
     * @param  Collection<int, EventQuestion>  $questions
     * @param  list<array{ticket_type_id: string, answers?: array<string, mixed>}>  $attendees
     * @param  array<string, int>  $quantities
     * @return list<array{ticket_type_id: string, index: int, answers: array<string, list<mixed>>}>
     */
    private function checkAttendees(Collection $questions, array $attendees, array $quantities): array
    {
        $counted = [];

        foreach ($attendees as $attendee) {
            $type = $attendee['ticket_type_id'];
            $counted[$type] = ($counted[$type] ?? 0) + 1;
        }

        // Compared both ways: too few leaves a ticket nobody is named on, and
        // too many means the page and the basket disagree about what is being
        // bought, which is not something to guess at.
        //
        // == rather than ===, deliberately: these are counts per ticket type,
        // and the order the two arrays happened to be built in is not part of
        // what is being compared.
        if ($counted != array_filter($quantities)) {
            throw new CheckoutException(
                'Tell us about each person you are buying for — one set of answers per ticket.'
            );
        }

        $out = [];
        $index = [];

        foreach ($attendees as $position => $attendee) {
            $type = $attendee['ticket_type_id'];
            $at = $index[$type] = ($index[$type] ?? -1) + 1;

            $given = $this->answersTo($questions, $attendee['answers'] ?? [], 'ticket '.($position + 1));

            $this->assertAnswered($questions, $given, $position + 1);

            $out[] = ['ticket_type_id' => $type, 'index' => $at, 'answers' => $given];
        }

        return $out;
    }

    /**
     * Take what was sent and keep only answers to questions that exist, each
     * one checked against how that question may be answered.
     *
     * @param  Collection<int, EventQuestion>  $questions
     * @param  array<string, mixed>  $given
     * @return array<string, list<mixed>>
     */
    private function answersTo(Collection $questions, array $given, string $whose): array
    {
        $out = [];

        foreach ($questions as $question) {
            $value = $given[$question->id] ?? null;

            if ($this->blank($value)) {
                continue;
            }

            $out[$question->id] = $this->clean($question, $value, $whose);
        }

        return $out;
    }

    /**
     * A value this question can actually hold.
     *
     * Choices are checked against the list the organizer wrote: a client
     * posting its own option is posting something nobody offered, and it would
     * land in a column an organizer reads as though they had.
     *
     * @return list<mixed>
     */
    private function clean(EventQuestion $question, mixed $value, string $whose): array
    {
        $options = $question->options ?? [];

        return match ($question->type) {
            'boolean' => [filter_var($value, FILTER_VALIDATE_BOOLEAN)],

            'choice' => in_array($value, $options, true)
                ? [$value]
                : throw new CheckoutException("Choose one of the options for “{$question->label}”."),

            'multi_choice' => $this->chosen($question, $value, $options),

            // Text, which is anything typed.
            default => [$this->text($question, $value, $whose)],
        };
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private function chosen(EventQuestion $question, mixed $value, array $options): array
    {
        $values = array_values(array_unique(is_array($value) ? $value : [$value]));

        foreach ($values as $one) {
            if (! in_array($one, $options, true)) {
                throw new CheckoutException("Choose from the options for “{$question->label}”.");
            }
        }

        return $values;
    }

    private function text(EventQuestion $question, mixed $value, string $whose): string
    {
        if (is_array($value)) {
            throw new CheckoutException("“{$question->label}” takes an answer in words.");
        }

        $text = trim((string) $value);

        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new CheckoutException(
                "That answer to “{$question->label}” for {$whose} is too long — keep it under "
                .self::MAX_TEXT.' characters.'
            );
        }

        return $text;
    }

    /**
     * Required means required, and the message names what is missing.
     *
     * "Please answer everything" sends somebody back up a form to hunt; the
     * labels tell them where to look, and which ticket when there is more than
     * one person on the order.
     *
     * @param  Collection<int, EventQuestion>  $questions
     * @param  array<string, list<mixed>>  $given
     */
    private function assertAnswered(Collection $questions, array $given, ?int $ticketNumber): void
    {
        $missing = $questions
            ->where('required', true)
            ->filter(fn (EventQuestion $question) => ! array_key_exists($question->id, $given));

        if ($missing->isEmpty()) {
            return;
        }

        $labels = $missing->pluck('label')->implode(', ');

        throw new CheckoutException(
            $ticketNumber === null
                ? "Please answer: {$labels}."
                : "Please answer for ticket {$ticketNumber}: {$labels}."
        );
    }

    /** @param  Collection<int, EventQuestion>  $questions */
    private function assertNothingRequired(Collection $questions): void
    {
        $required = $questions->where('required', true);

        if ($required->isNotEmpty()) {
            throw new CheckoutException(
                'Please answer for each person: '.$required->pluck('label')->implode(', ').'.'
            );
        }
    }

    private function blank(mixed $value): bool
    {
        if (is_bool($value)) {
            // False is an answer to "do you need step-free access", and the
            // one an organizer plans around. blank() would throw it away.
            return false;
        }

        return $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
    }
}
