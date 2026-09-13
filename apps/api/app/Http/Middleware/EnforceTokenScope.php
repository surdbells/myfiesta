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

        /*
         * Somebody's own account: who they are, their name, their password.
         *
         * Any token that speaks for a person reaches it; a door token does not.
         * A door pass belongs, as Sanctum sees it, to the member who issued it
         * — so without this the phone handed to the door for the night could
         * read that member's profile and rename them.
         */
        if ($required === 'account') {
            if ($this->isDoorToken($token->abilities ?? [])) {
                return $this->deny('A door token is limited to check-in for its own event.');
            }

            return $next($request);
        }

        /*
         * The door takes two kinds of token.
         *
         * A `door:{event_id}` token, which carries the event it was minted for
         * and reaches nothing else — that is why an exact-match check is not
         * enough here.
         *
         * Or an ordinary organizer token, because organizers work their own
         * doors constantly and issuing themselves a door token to stand at
         * their own event would be ceremony for its own sake. Both the route
         * and the controller have always documented this; the check did not
         * implement it, so an organizer scanning their own door got a 403 and
         * the console had no way to scan at all.
         *
         * The ability alone is not authority. An organizer token says only
         * "somebody organizes something" — DoorController authorizes 'scan'
         * against this specific event, which is what stops one organizer
         * scanning another's door.
         */
        if ($required === TokenAbility::Door->value) {
            $eventId = $request->route('event')?->id ?? $request->route('event');

            $hasDoorToken = is_string($eventId) && $token->can(TokenAbility::doorFor($eventId));

            if (! $hasDoorToken && ! $token->can(TokenAbility::Organizer->value)) {
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
