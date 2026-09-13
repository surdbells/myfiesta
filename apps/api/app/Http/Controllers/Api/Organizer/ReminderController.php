<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which reminders an event sends, and when.
 *
 * Sensible ones exist from the moment an event is published, so this screen is
 * for changing a decision rather than making one. Most organizers will never
 * open it, which is the point.
 */
class ReminderController extends Controller
{
    /**
     * The most scheduled reminders one event may have. Each one is an email
     * to everybody holding a ticket; past a handful they stop being reminders.
     */
    public const MAX_PER_EVENT = 10;

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json([
            'data' => $event->reminders->map(fn (EventReminder $r) => $this->present($r))->values(),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            // Ninety-one days, matching the database constraint. Beyond that
            // an organizer is scheduling a reminder for an event most of the
            // audience has not bought a ticket to yet.
            'offset_minutes' => ['required', 'integer', 'min:1', 'max:131400'],
        ], [
            'offset_minutes.max' => 'Reminders can go out up to about three months ahead.',
        ]);

        if ($event->reminders()->where('status', '!=', 'cancelled')->count() >= self::MAX_PER_EVENT) {
            return response()->json([
                'message' => 'An event can have up to '.self::MAX_PER_EVENT.' reminders.',
            ], 422);
        }

        if ($event->reminders()->where('offset_minutes', $data['offset_minutes'])->exists()) {
            return response()->json([
                'message' => 'There is already a reminder at that point.',
            ], 422);
        }

        if ($event->starts_at->copy()->subMinutes($data['offset_minutes'])->isPast()) {
            // Refused rather than stored and quietly skipped. A reminder that
            // appears in the list and never sends is worse than one that was
            // never created, because the organizer stops watching for it.
            return response()->json([
                'message' => 'That moment has already passed for this event.',
            ], 422);
        }

        $reminder = $event->reminders()->create([
            'offset_minutes' => $data['offset_minutes'],
            'status' => 'scheduled',
        ]);

        return response()->json($this->present($reminder), 201);
    }

    /**
     * Turn one off.
     *
     * Cancelled rather than deleted, so an organizer looking at an event later
     * can see that a reminder existed and was stopped — and so the delivery
     * records that prove who was already emailed survive.
     */
    public function destroy(Request $request, Event $event, EventReminder $reminder): JsonResponse
    {
        $this->authorize('update', $event);

        abort_unless($reminder->event_id === $event->id, 404);

        if ($reminder->status === 'sent') {
            return response()->json([
                'message' => 'That one has already gone out.',
            ], 422);
        }

        $reminder->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Turned off.']);
    }

    private function present(EventReminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'offset_minutes' => $reminder->offset_minutes,
            'label' => $reminder->describe(),
            'send_at' => $reminder->sendAt(),
            'status' => $reminder->status,
            'sent_at' => $reminder->sent_at,
            'recipients' => $reminder->recipients,
        ];
    }
}
