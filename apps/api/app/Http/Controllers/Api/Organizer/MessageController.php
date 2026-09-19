<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\EventMessage;
use App\Services\Messaging\MessageSender;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writing to the people holding tickets.
 *
 * Without this an organizer with news had no way to reach their own audience
 * except by exporting a guest list into their personal mail client — which is
 * how a ticket buyer's address ends up in somebody's Gmail contacts forever,
 * outside every opt-out this platform honours.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessageSender $sender) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        $messages = $event->messages()->orderByDesc('id')->paginate(Paging::perPage($request, 20));

        return response()->json([
            'data' => $messages->getCollection()->map(fn (EventMessage $m) => $this->present($m))->values(),
            'meta' => Paging::meta($messages),
            // What a send would reach right now, so the number is on screen
            // before the button is pressed rather than after.
            'audience' => $this->audience($event),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('message', $event);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
            'important' => ['sometimes', 'boolean'],
        ], [
            'subject.required' => 'Give it a subject — it is what people see first.',
            'body.required' => 'There is nothing to send.',
        ]);

        $audience = $this->audience($event);

        if ($audience['reachable'] === 0) {
            // Refused rather than queued and quietly delivered to nobody. An
            // organizer who thinks a message went out and finds later that it
            // did not is worse off than one told immediately.
            return response()->json([
                'message' => 'Nobody holds a ticket to this event yet.',
            ], 422);
        }

        $message = $event->messages()->create([
            'sent_by' => $request->user()->id,
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'important' => (bool) ($data['important'] ?? false),
            'status' => 'queued',
        ]);

        $sent = $this->sender->send($message);

        return response()->json([
            'message' => $sent === 1 ? 'Sent to 1 person.' : "Sent to {$sent} people.",
            'data' => $this->present($message->refresh()),
        ], 201);
    }

    /**
     * How many hold a ticket, and how many of those will actually get it.
     *
     * Both numbers, because the gap is the thing an organizer misreads. Seeing
     * "412 tickets" and "389 will receive this" together is what stops the
     * support email asking why the count was wrong.
     */
    private function audience(Event $event): array
    {
        $holders = $event->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            // A walk-up who paid cash at the door may have left no address.
            ->whereNotNull('owner_email')
            ->pluck('owner_email')
            ->map(fn (string $email) => EmailPreference::normalise($email))
            ->unique()
            ->values()
            ->all();

        return [
            'holders' => count($holders),
            'reachable' => count(EmailPreference::remindable($holders)),
        ];
    }

    private function present(EventMessage $message): array
    {
        return [
            'id' => $message->id,
            'subject' => $message->subject,
            'body' => $message->body,
            'important' => $message->important,
            'status' => $message->status,
            'sent_at' => $message->sent_at,
            'recipients' => $message->recipients,
            'suppressed' => $message->suppressed,
        ];
    }
}
