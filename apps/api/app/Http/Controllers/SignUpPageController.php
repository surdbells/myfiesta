<?php

namespace App\Http\Controllers;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\Accounts\SignUps;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The page behind the link that finishes a sign-up.
 *
 * Served here rather than on the console for the same reason the privacy
 * pages are: it has to work with no account, no session and no JavaScript,
 * often in a mail client's own browser — and the people signing up from the
 * phone app have no console to land on.
 *
 * A GET never acts. Mail scanners follow links in messages, and an account
 * made by a prefetch would be an account made for whoever sent the scanner
 * the email, not for whoever reads it.
 *
 * And the POST asks for the password that was chosen. Opening the link proves
 * somebody reads the inbox; typing the password proves they are also the one
 * who filled in the form. Without both, a stranger could sign up with
 * somebody else's address, wait for its owner to click through a confusing
 * email, and hold the password to an account every ticket that address buys
 * as a guest would then land in.
 *
 * The password may be the one for any sign-up still waiting on the address,
 * not only the one this link was sent for (SignUps::opens()). Emails to one
 * address are limited per hour, and a stranger can use the hour up; the
 * owner's own sign-up then has no email of its own, and the stranger's links
 * in the owner's inbox are how it is finished.
 */
class SignUpPageController extends Controller
{
    /** Wrong passwords one link may take before it stops listening, per hour. */
    private const TRIES = 5;

    public function __construct(private readonly SignUps $signUps) {}

    public function show(Request $request, string $registration): Response
    {
        $pending = $this->find($request, $registration);

        if ($pending === null) {
            return $this->page('expired', status: 410);
        }

        return $this->confirmPage($request, $pending);
    }

    public function confirm(Request $request, string $registration): Response
    {
        $pending = $this->find($request, $registration);

        if ($pending === null) {
            return $this->page('expired', status: 410);
        }

        $tries = 'sign-up-page:'.$pending->id;

        if (RateLimiter::tooManyAttempts($tries, self::TRIES)) {
            return $this->confirmPage($request, $pending, 'That was the wrong password too many times. Sign up again and a new link will come to this address.', 429);
        }

        $chosen = $this->signUps->opens($pending, (string) $request->input('password'));

        if ($chosen === null) {
            RateLimiter::hit($tries, 3600);

            return $this->confirmPage($request, $pending, 'That is not the password this account was asked for with. Type the one you chose when you signed up.', 422);
        }

        RateLimiter::clear($tries);

        $outcome = $this->signUps->complete($chosen->id);

        return match (true) {
            $outcome instanceof User => $this->page('done', [
                'user' => $outcome,
                'organizer' => $outcome->organizations()->exists(),
            ]),
            $outcome === 'taken' => $this->page('taken'),
            default => $this->page('expired', status: 410),
        };
    }

    /**
     * The sign-up this link is for, if the link is genuine and still good.
     *
     * One answer for a link that has expired, has been used, was tampered
     * with or never existed: none of them can be put right from here, and all
     * of them are put right the same way — by signing up again.
     */
    private function find(Request $request, string $id): ?PendingRegistration
    {
        if (! $request->hasValidRelativeSignature()) {
            return null;
        }

        $pending = PendingRegistration::find($id);

        return $pending !== null && ! $pending->hasExpired() ? $pending : null;
    }

    private function confirmPage(Request $request, PendingRegistration $pending, ?string $error = null, int $status = 200): Response
    {
        return $this->page('confirm', [
            'pending' => $pending,
            'action' => $request->getRequestUri(),
            'error' => $error,
        ], $status);
    }

    /** @param  array<string, mixed>  $with */
    private function page(string $state, array $with = [], int $status = 200): Response
    {
        return response()->view('account-link', [
            'kind' => 'sign-up',
            'state' => $state,
            'console' => rtrim((string) config('app.console_url'), '/'),
            ...$with,
        ], $status);
    }
}
