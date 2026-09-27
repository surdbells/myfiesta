<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's session lasts as long as staff want it to.
 *
 * Staff asked not to be timed out: sign in once, with the emailed code, and
 * stay in until they sign out. The ordinary session lifetime is two hours,
 * which is right for everything else a browser session is started for here —
 * webhook posts, unsubscribe pages — and wrong for somebody who leaves the
 * admin open in a tab all week.
 *
 * So this runs before the session starts, on the admin's routes and on
 * Livewire's update endpoint (which every table, filter and action in the
 * admin posts to), and lengthens the lifetime for that request only. Nothing
 * outside the admin is affected: the config is set per request, and a PHP
 * process serves one request.
 *
 * It has to be before StartSession, because that is where the handler's idle
 * check is decided. Behind it, the remember cookie covers a browser that was
 * closed, and StaffSessionHandler stops the rest of the site's garbage
 * collection from deleting an idle staff session out from under an open tab.
 *
 * Whether the session cookie itself survives closing the browser is the
 * person's choice at sign-in ("Keep me signed in", ticked by default), and
 * that choice lives in the session, which does not exist yet here. So this
 * starts every admin request as a browser-session cookie, and AuthenticateStaff
 * — or the sign-in page, on the request that signs in — makes it the long one
 * for a sign-in that asked to be kept. Unticked on a shared computer, closing
 * the browser signs them out.
 */
class KeepStaffSignedIn
{
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.lifetime' => max(
                (int) config('session.lifetime'),
                (int) config('session.staff_lifetime'),
            ),
            'session.expire_on_close' => true,
        ]);

        return $next($request);
    }
}
