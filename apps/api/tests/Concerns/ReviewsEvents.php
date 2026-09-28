<?php

namespace Tests\Concerns;

use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventReviews;
use Illuminate\Testing\TestResponse;

/**
 * Putting an event on sale the way it happens now.
 *
 * Nothing goes on sale without an approval (EventReviews), so a test that
 * needs an event on sale through the API asks for it here: the organizer
 * signed in sends it for review, and a member of staff approves it. One place,
 * so the next change to how events reach the public is one change to the
 * tests rather than thirty.
 */
trait ReviewsEvents
{
    private ?User $eventReviewer = null;

    /**
     * Send the event for review as whoever is signed in, then approve it.
     *
     * The response is the organizer's: a refusal (not ready, not allowed) is
     * returned as it came and nothing is approved. An event that went straight
     * back on sale — an approval still stood for it — is not approved again.
     */
    protected function publishThroughReview(Event $event): TestResponse
    {
        $response = $this->postJson("/api/organizer/events/{$event->id}/submit");

        if ($response->isSuccessful() && $response->json('status') === 'in_review') {
            $this->approveEvent($event);
        }

        return $response;
    }

    /** A member of staff approves what is waiting. */
    protected function approveEvent(Event $event): string
    {
        return app(EventReviews::class)->approve($event->fresh(), $this->eventReviewer());
    }

    protected function rejectEvent(Event $event, string $reason = 'The poster is from a different event. Upload this night’s own.'): void
    {
        app(EventReviews::class)->reject($event->fresh(), $this->eventReviewer(), $reason);
    }

    /** Somebody at myFiesta who reviews events: made once per test, when first needed. */
    protected function eventReviewer(PlatformRole $role = PlatformRole::Support): User
    {
        return $this->eventReviewer ??= User::factory()->create([
            'platform_role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
