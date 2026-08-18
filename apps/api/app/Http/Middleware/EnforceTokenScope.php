<?php

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mode boundary, enforced where it actually counts.
 *
 * Attendee, organizer, and door are one Flutter binary, so a door-staff phone
 * ships with the organizer interface inside it — hidden, but present and
 * reachable by anyone willing to inspect the app. Hiding it is a convenience.
 * This is the boundary.
 *
 * A door token is issued as `door:{event_id}`. It may reach check-in for that
 * one event and nothing else. It is rejected outright from sales, guest lists,
 * payouts, profile, and code management regardless of what role the underlying
 * user holds — a venue owner scanning their own door is still, for the life of
 * that token, only scanning.
 *
 * Registered as `token.scope:<ability>` on every authenticated route.
 */
class EnforceTokenScope
{
    public function handle(Request $request, Closure $next, string $required): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return $this->deny('This endpoint requires an API token.');
        }

        // Door tokens are event-scoped, so an exact-match check is not enough:
        // the ability carries the event id it was minted for.
        if ($required === TokenAbility::Door->value) {
            $eventId = $request->route('event')?->id ?? $request->route('event');

            if (! is_string($eventId) || ! $token->can(TokenAbility::doorFor($eventId))) {
                return $this->deny('This token does not grant access to this door.');
            }

            return $next($request);
        }

        if (! $token->can($required)) {
            return $this->deny("This token lacks the '{$required}' ability.");
        }

        // A door token must never satisfy a broader requirement, even if the
        // user behind it would pass on role alone. Without this, handing a
        // phone to staff for the night would hand over the whole account.
        if ($this->isDoorToken($token->abilities ?? [])) {
            return $this->deny('A door token is limited to check-in for its own event.');
        }

        return $next($request);
    }

    /** @param array<int, string> $abilities */
    private function isDoorToken(array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if (str_starts_with($ability, TokenAbility::Door->value.':')) {
                return true;
            }
        }

        return false;
    }

    private function deny(string $detail): Response
    {
        return response()->json([
            'type' => 'https://myfiesta.ca/problems/insufficient-token-scope',
            'title' => 'Insufficient token scope',
            'status' => 403,
            'detail' => $detail,
        ], 403, ['Content-Type' => 'application/problem+json']);
    }
}
