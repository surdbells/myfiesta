<?php

namespace App\Http\Middleware;

use App\Support\Session\StaffSignIn;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Session\Session;

/**
 * Filament's admin check, which also signs out somebody who is no longer
 * staff, or who never got past the emailed code.
 *
 * Staff sessions are long on purpose — nobody is timed out — which makes this
 * the thing that stops a long session outliving the job. The user is loaded
 * fresh on every request, so the check is the one the sign-in makes, and it
 * runs on Livewire's requests too (the panel registers it as persistent), so
 * a table left open cannot keep acting after the role is gone.
 *
 * Filament's own check answers 403 and leaves the session standing, and a
 * session left standing comes back to life if the role is ever given back —
 * possibly in somebody else's browser, if that is where it was. So this signs
 * the person out first, which also cycles the remember token and leaves no
 * copy of the cookie that works either. Still a 403 rather than a redirect:
 * they were signed in, and being signed in was never the thing missing.
 *
 * Being signed in is not the same as having passed the code, either. A session
 * signed in before codes were required, or by anything other than the admin's
 * sign-in page, carries no record of the code (StaffSignIn) and is signed out
 * and sent to sign in properly — this time the thing missing is the sign-in.
 *
 * It also decides whether this session's cookie outlives the browser: only if
 * the sign-in asked to be kept. KeepStaffSignedIn cannot, because it runs
 * before there is a session to ask.
 *
 * A subclass rather than a middleware beside it: Laravel sorts authentication
 * middleware ahead of anything it does not recognise, so a separate check
 * placed "before" Filament's would in fact run after it, too late.
 */
class AuthenticateStaff extends Authenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();
        $user = $guard->user();

        if ($user instanceof FilamentUser
            && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            $guard->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(403);
        }

        if ($user !== null && $request->hasSession()
            && ! $this->cameInThroughTheCode($request->session(), $guard, $user)) {
            $guard->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Nobody is signed in now, so Filament's check below sends them
            // to the sign-in page.
        }

        parent::authenticate($request, $guards);

        if ($request->hasSession()) {
            // Read at the end of the request, when the session cookie is
            // written: kept sign-ins get the long one, the rest end with the
            // browser.
            config(['session.expire_on_close' => ! StaffSignIn::keepsSignedIn($request->session())]);
        }
    }

    private function cameInThroughTheCode(Session $session, Guard $guard, Authenticatable $user): bool
    {
        if (StaffSignIn::passedTheCode($session, $user)) {
            return true;
        }

        if (! $guard instanceof SessionGuard) {
            return false;
        }

        /*
         * Back through "keep me signed in". That cookie is only ever issued
         * once the code has passed, and the ones issued before codes were
         * required stopped working when they were (the migration that clears
         * staff remember tokens). Only somebody who ticked the box has one.
         */
        if ($guard->viaRemember()) {
            StaffSignIn::record($session, $user, keepSignedIn: true);

            return true;
        }

        /*
         * Put on the guard for this one request and never signed in to a
         * browser session — nothing in the app does this; a test's actingAs()
         * does. With no browser sign-in there is no code step that was
         * skipped, and nothing that outlives the request.
         */
        return ! $session->has($guard->getName());
    }
}
