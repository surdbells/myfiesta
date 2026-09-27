<?php

namespace App\Support\Session;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;

/**
 * What a staff member's browser session knows about how they signed in.
 *
 * Written by the admin's sign-in page once the password and the emailed code
 * have both passed (App\Filament\Auth\Login), and read by AuthenticateStaff on
 * every admin request, Livewire's included. Two things:
 *
 *  - That this session got past the code, for this account. Being signed in
 *    to the web guard is not enough on its own: a session from before codes
 *    were required, or from any other way into that guard, never saw one.
 *    AuthenticateStaff signs such a session out.
 *
 *  - Whether they asked to be kept signed in. Only then does the session
 *    cookie outlive the browser; unticked, closing the browser ends it, which
 *    is what the sign-in page tells somebody on a shared computer.
 *
 * Kept in the session rather than a column: it describes one browser, and it
 * goes when that session does — signing out, a new session id, or the row
 * being deleted when access is revoked.
 */
final class StaffSignIn
{
    private const KEY = 'staff_sign_in';

    public static function record(Session $session, Authenticatable $user, bool $keepSignedIn): void
    {
        $session->put(self::KEY, [
            'user' => (string) $user->getAuthIdentifier(),
            'keep' => $keepSignedIn,
        ]);
    }

    /** Whether this session was signed in through the code, as this account. */
    public static function passedTheCode(Session $session, Authenticatable $user): bool
    {
        $record = $session->get(self::KEY);

        return is_array($record)
            && ($record['user'] ?? null) === (string) $user->getAuthIdentifier();
    }

    public static function keepsSignedIn(Session $session): bool
    {
        $record = $session->get(self::KEY);

        return is_array($record) && ($record['keep'] ?? false) === true;
    }
}
