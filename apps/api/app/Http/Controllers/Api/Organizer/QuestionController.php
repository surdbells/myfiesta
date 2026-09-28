<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Services\Audit\Auditor;
use App\Services\Events\EventReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What an event asks the people coming to it.
 *
 * The same questions a wedding asks its guests when they RSVP, put in front of
 * somebody buying a ticket: the name for the door, a phone number for a table,
 * dietary requirements for a dinner, how they heard about the night.
 *
 * Questions are answered by strangers and read back by an organizer, so this
 * is the only place the shape of an answer is decided. The checkout validates
 * against these rows and never against what a page claims to have rendered.
 */
class QuestionController extends Controller
{
    /**
     * A form somebody has to fill in before they can pay.
     *
     * Ten is already more than anybody should be asked on the way to a night
     * out, and the limit exists because the alternative is an organizer
     * discovering the number by watching their conversion rate fall.
     */
    public const MAX_PER_EVENT = 10;

    /** As many options as fit on a phone without becoming a scroll. */
    public const MAX_OPTIONS = 20;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);

        return response()->json(['data' => $this->list($event)]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        $data = $this->validated($request);

        if ($event->questions()->count() >= self::MAX_PER_EVENT) {
            return response()->json([
                'message' => 'An event can ask up to '.self::MAX_PER_EVENT.' questions. Remove one to add another.',
            ], 422);
        }

        $question = $event->questions()->create($data + [
            // Appended, so a new question does not land in the middle of a
            // form somebody has already thought about the order of.
            'sort_order' => (int) $event->questions()->max('sort_order') + 1,
        ]);

        $this->auditor->record('question.added', $event, $request->user(), metadata: [
            'question_id' => $question->id,
            'label' => $question->label,
        ]);

        return response()->json(['data' => $this->present($question)], 201);
    }

    public function update(Request $request, Event $event, EventQuestion $question): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        abort_unless($question->event_id === $event->id, 404);

        $data = $this->validated($request, $question);

        /*
         * What may still be changed once somebody has answered.
         *
         * A label can be corrected — a typo in a question does not invalidate
         * the answers to it. How it is answered cannot: turning a text
         * question into a choice would leave every answer already given
         * outside the list of things it was possible to say, and an organizer
         * reading the export would have no way to tell which were which.
         */
        if ($this->answered($question)) {
            $locked = array_filter(
                ['type', 'per_attendee'],
                fn (string $field) => array_key_exists($field, $data) && $data[$field] !== $question->{$field},
            );

            if ($locked !== []) {
                return response()->json([
                    'message' => 'People have already answered this question, so how it is answered cannot change. '
                        .'Remove it and add a new one if you need to ask something different.',
                ], 422);
            }
        }

        $before = $question->only(array_keys($data));

        $question->update($data);

        // Reworded on an event that is on sale: allowed without another
        // review, like any edit while on sale, and kept on the record.
        $changed = array_values(array_diff(array_keys($question->getChanges()), ['updated_at']));

        if ($changed !== [] && $event->status === 'published') {
            $this->auditor->record('question.updated', $event, $request->user(), metadata: [
                'question_id' => $question->id,
                'changed' => $changed,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => $question->only($changed),
            ]);
        }

        return response()->json(['data' => $this->present($question->fresh())]);
    }

    /**
     * Stop asking it.
     *
     * Soft-deleted, always. The answers already given are the reason the
     * question was asked — an organizer tidying their form the week after the
     * night would otherwise delete the dietary requirements they collected it
     * for, and the export would lose the heading that makes a column mean
     * anything.
     */
    public function destroy(Request $request, Event $event, EventQuestion $question): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        abort_unless($question->event_id === $event->id, 404);

        $question->delete();

        $this->auditor->record('question.removed', $event, $request->user(), metadata: [
            'question_id' => $question->id,
            'label' => $question->label,
        ]);

        return response()->json([
            'message' => $this->answered($question)
                ? 'No longer asked. The answers people already gave are kept.'
                : 'No longer asked.',
        ]);
    }

    /**
     * The order they are asked in.
     *
     * One request for the whole list rather than a sort_order per question, as
     * with ticket types: two questions can never end up claiming the same
     * place, which is what happens when a client swaps a pair with two writes
     * and the second fails.
     */
    public function reorder(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manageTickets', $event);
        EventReviews::refuseWhileInReview($event);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ]);

        $owned = $event->questions()->pluck('id')->all();

        if (array_diff($data['ids'], $owned) !== []) {
            return response()->json([
                'message' => 'That list includes a question that is not on this event.',
            ], 422);
        }

        DB::transaction(function () use ($data, $event) {
            foreach ($data['ids'] as $position => $id) {
                $event->questions()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['data' => $this->list($event)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?EventQuestion $question = null): array
    {
        $updating = $question !== null;
        $required = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'label' => [$required, 'string', 'max:120'],
            'type' => [$required, Rule::in(EventQuestion::TYPES)],
            'required' => ['sometimes', 'boolean'],
            'per_attendee' => ['sometimes', 'boolean'],

            'options' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'options.*' => ['string', 'max:80'],
        ]);

        $type = $data['type'] ?? $question?->type;

        if (in_array($type, ['choice', 'multi_choice'], true)) {
            $options = array_values(array_filter(
                array_map('trim', $data['options'] ?? $question?->options ?? []),
                fn (string $option) => $option !== '',
            ));

            // A choice with nothing to choose from is a question nobody can
            // answer, which on a required question is a checkout nobody can
            // finish.
            if (count($options) < 2) {
                abort(response()->json([
                    'message' => 'A question people choose an answer to needs at least two options.',
                ], 422));
            }

            if (count(array_unique($options)) !== count($options)) {
                abort(response()->json([
                    'message' => 'Two options with the same wording cannot be told apart in an answer.',
                ], 422));
            }

            $data['options'] = $options;
        } elseif (array_key_exists('type', $data)) {
            // Typed answers have no options, and leaving stale ones behind
            // would show a list on a screen built from these rows.
            $data['options'] = null;
        }

        return $data;
    }

    private function answered(EventQuestion $question): bool
    {
        return DB::table('order_answers')->where('event_question_id', $question->id)->exists()
            || DB::table('rsvp_answers')->where('event_question_id', $question->id)->exists();
    }

    /** @return list<array<string, mixed>> */
    private function list(Event $event): array
    {
        return $event->questions()->get()->map(fn (EventQuestion $q) => $this->present($q))->all();
    }

    /** @return array<string, mixed> */
    private function present(EventQuestion $question): array
    {
        return [
            'id' => $question->id,
            'label' => $question->label,
            'type' => $question->type,
            'options' => $question->options ?? [],
            'required' => $question->required,
            'per_attendee' => $question->per_attendee,
            'sort_order' => $question->sort_order,
            // Whether the shape of it is still free to change, said here so
            // the console can grey the controls rather than discover it on a
            // failed save.
            'answered' => $this->answered($question),
        ];
    }
}
