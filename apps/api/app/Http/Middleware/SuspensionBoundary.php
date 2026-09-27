<?php

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\User;
use App\Services\Organizations\Suspension;
use App\Services\Organizations\WhileSuspended;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The edge of what a suspended organization can do.
 *
 * On the whole api group, like ImpersonationBoundary, so a door phone, the
 * console and an organizer's own token meet the same rule. Inert for every
 * endpoint WhileSuspended does not name: the organization is only looked up
 * for the handful that would publish, sell or pay out, and everything else —
 * reading above all — passes straight through.
 *
 * The organization is the event's, for anything under an event; otherwise
 * the one the console is working in (X-Organization), or the member's only
 * one — the same answer the controllers behind these endpoints reach.
 */
class SuspensionBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()?->getActionName();

        if (! WhileSuspended::guards($action)) {
            return $next($request);
        }

        $refusal = WhileSuspended::refusalFor($action, $request);

        if ($refusal === null) {
            return $next($request);
        }

        $organizationId = $this->organizationId($request);

        if ($organizationId === null
            || ! $this->actsFor($request, $organizationId)
            || ! Suspension::inForce($organizationId)) {
            return $next($request);
        }

        $detail = WhileSuspended::withContact($refusal);

        return response()->json([
            'type' => 'https://myfiesta.ca/problems/organization-suspended',
            'title' => 'Organization suspended',
            'status' => 403,
            'detail' => $detail,
            // The console shows `message` from any 4xx; `detail` is the
            // problem+json field other clients read.
            'message' => $detail,
        ], 403, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * Whose request this is, without trusting anything the caller could
     * point elsewhere: an event's own organization, or one the signed-in
     * person is a member of.
     */
    private function organizationId(Request $request): ?string
    {
        // Bound or not yet, depending on where in the stack this runs.
        $event = $request->route('event');

        if ($event instanceof Event) {
            return $event->organization_id;
        }

        if (is_string($event)) {
            return Str::isUuid($event)
                ? Event::withTrashed()->whereKey($event)->value('organization_id')
                : null;
        }

        $user = $request->user('sanctum');

        if (! $user instanceof User) {
            return null;
        }

        $memberships = $user->organizations->pluck('id')->all();
        $asked = $request->header('X-Organization');

        if (filled($asked)) {
            return in_array($asked, $memberships, true) ? $asked : null;
        }

        return $memberships[0] ?? null;
    }

    /**
     * Whether the caller works for this organization — a member, or a door
     * pass for one of its nights.
     *
     * Anybody else is passed through to be refused by the controller as
     * they would be on any day: telling a stranger that somebody else's
     * organization is suspended, because they guessed an event id, is not
     * this middleware's to say.
     */
    private function actsFor(Request $request, string $organizationId): bool
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User) {
            return false;
        }

        if ($user->organizations->contains('id', $organizationId)) {
            return true;
        }

        $event = $request->route('event');
        $eventId = $event instanceof Event ? $event->id : (is_string($event) ? $event : null);

        return $eventId !== null && $user->currentAccessToken()?->can(TokenAbility::doorFor($eventId)) === true;
    }
}
