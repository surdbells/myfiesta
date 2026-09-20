<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\OrderAnswer;
use App\Models\Ticket;
use App\Services\Audit\Auditor;
use App\Support\Csv;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who is coming, and who has arrived.
 *
 * Door staff are deliberately excluded by the policy. Scanning is a separate
 * ability tied to one event and does not carry the right to read the list —
 * somebody handed a phone for one night should not also be handed every
 * attendee's name and email.
 */
class GuestController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * The guest list as a spreadsheet — to print for a door, or to hand to a
     * venue that wants names in advance.
     *
     * Everybody holding a ticket that still works, alphabetical by name.
     * Without ticket codes, for the same reason the list on screen leaves them
     * out: a printed or forwarded guest list must not be a set of working
     * tickets. Recorded, because it is personal data leaving the platform in
     * bulk.
     */
    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorize('viewGuests', $event);

        $query = Ticket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['valid', 'checked_in']);

        $this->auditor->record(
            'guests.exported',
            $event,
            $request->user(),
            metadata: ['count' => (clone $query)->count()],
        );

        // One column per question the event asked, including ones it has
        // stopped asking: the answers outlive the question, and a column of
        // dietary requirements with no heading is a column nobody can read.
        $questions = $this->questionsAnswered($event);

        $rows = (function () use ($query, $event, $questions) {
            $tickets = $query
                ->with(['ticketType:id,name', 'order:id,reference', 'answers', 'order.answers'])
                ->orderBy('holder_name')
                ->orderBy('id');

            foreach ($tickets->lazy(500) as $ticket) {
                $answers = $this->answersFor($ticket);

                yield [
                    Csv::text($ticket->holder_name),
                    Csv::text($ticket->owner_email),
                    Csv::text($ticket->ticketType?->name),
                    $ticket->admits,
                    $ticket->admitted_count,
                    $ticket->status === 'checked_in' ? 'Arrived' : ($ticket->admitted_count > 0 ? 'Partly arrived' : 'Expected'),
                    // At the venue's clock, which is the night the list is for.
                    $ticket->checked_in_at?->copy()->setTimezone($event->timezone)->format('Y-m-d H:i'),
                    $ticket->order?->reference,
                    // Every answer is text somebody typed at a checkout, so it
                    // goes through the same defusing as a name.
                    ...array_map(
                        fn (EventQuestion $question) => Csv::text($answers[$question->id] ?? null),
                        $questions,
                    ),
                ];
            }
        })();

        return Csv::download(
            Str::slug($event->title).'-guests-'.now()->format('Y-m-d').'.csv',
            [
                'Name', 'Email', 'Ticket', 'Admits', 'Arrived', 'Status', 'First arrived at', 'Order reference',
                ...array_map(fn (EventQuestion $question) => $question->label, $questions),
            ],
            $rows,
        );
    }

    /**
     * The questions worth a column, in the order they were asked.
     *
     * Every question the event still asks, plus any it has stopped asking that
     * somebody answered. A question removed before anybody answered it is not
     * an empty column in a spreadsheet; one removed afterwards is a heading
     * that has to survive, or the answers under it mean nothing.
     *
     * @return list<EventQuestion>
     */
    private function questionsAnswered(Event $event): array
    {
        return EventQuestion::withTrashed()
            ->where('event_id', $event->id)
            ->where(fn ($query) => $query
                ->whereNull('deleted_at')
                ->orWhereExists(fn ($exists) => $exists
                    ->selectRaw(1)
                    ->from('order_answers')
                    ->whereColumn('order_answers.event_question_id', 'event_questions.id')))
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /**
     * One ticket's answers, keyed by question.
     *
     * Its holder's own, and whatever the buyer answered for the order it is
     * part of — on a spreadsheet the second repeats down the rows of one
     * order, which is what a reader expects of a flat list.
     *
     * @return array<string, string>
     */
    private function answersFor(Ticket $ticket): array
    {
        $answers = $ticket->answers->merge(
            $ticket->order?->answers->whereNull('order_line_id') ?? []
        );

        return $answers
            ->mapWithKeys(fn (OrderAnswer $answer) => [$answer->event_question_id => $answer->asText()])
            ->all();
    }

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('viewGuests', $event);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:valid,checked_in'],
            // One tier at a time, which is how a door with a VIP list and a
            // general queue actually works.
            'ticket_type_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $guests = Ticket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['valid', 'checked_in'])
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
            )
            ->when(
                $filters['ticket_type_id'] ?? null,
                fn ($q, $id) => $q->where('ticket_type_id', $id),
            )
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Names get typed at a door under time pressure, so matching is
                // loose on both the name and the address.
                $like = '%'.str_replace('%', '\%', mb_strtolower($term)).'%';

                $q->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(holder_name) LIKE ?', [$like])
                        ->orWhereRaw('lower(owner_email) LIKE ?', [$like]);
                });
            })
            ->with(['ticketType:id,name', 'answers.question', 'order:id', 'order.answers.question'])
            ->orderBy('holder_name')
            // Names repeat, and a comp issued without one has none at all;
            // ordered by name alone, the same guest could appear on two pages
            // and another on neither.
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => $guests->getCollection()->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'name' => $ticket->holder_name,
                'email' => $ticket->owner_email,
                'ticket_type' => $ticket->ticketType?->name,
                // The code itself is not listed. A guest list is read on a
                // laptop in an office; a leaked screenshot of it should not be
                // a set of working tickets.
                'checked_in' => $ticket->status === 'checked_in',
                'checked_in_at' => $ticket->checked_in_at,
                // What this person was asked at checkout, and what the buyer
                // answered for the order they are on. Which of the two it was
                // is not a distinction anybody reading a guest list is making.
                'answers' => $ticket->answers
                    ->merge($ticket->order?->answers->whereNull('order_line_id') ?? [])
                    ->map(fn (OrderAnswer $answer) => [
                        'label' => $answer->question?->label,
                        'value' => $answer->asText(),
                    ])
                    ->values(),
            ])->values(),
            'meta' => [
                ...Paging::meta($guests),
                'checked_in' => Ticket::where('event_id', $event->id)
                    ->where('status', 'checked_in')
                    ->count(),
            ],
        ]);
    }
}
