<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Accounts\EmailVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The page behind the link that proves an existing account's address.
 *
 * The same shape as the sign-up page, for the same reasons: plain HTML on this
 * API, and a GET that only shows what will happen, because mail scanners
 * follow links.
 *
 * The POST asks for the account's password, as the sign-up page does. The
 * accounts that reach this page unproved are mostly ones made before sign-ups
 * waited for their address — and one of those can have been made by anybody,
 * with anybody's address. The owner of that inbox clicking "confirm" on an
 * email they did not expect must not be what lets such an account publish
 * events or point payouts somewhere.
 */
class EmailVerificationPageController extends Controller
{
    /** Wrong passwords one account may give here before it stops listening, per hour. */
    private const TRIES = 5;

    public function __construct(private readonly EmailVerification $verification) {}

    public function show(Request $request, string $user, string $hash): Response
    {
        $account = $this->find($request, $user, $hash);

        if ($account === null) {
            return $this->page('expired', status: 410);
        }

        if ($account->email_verified_at !== null) {
            return $this->page('verified', ['email' => $account->email]);
        }

        return $this->confirmPage($request, $account);
    }

    public function confirm(Request $request, string $user, string $hash): Response
    {
        $account = $this->find($request, $user, $hash);

        if ($account === null) {
            return $this->page('expired', status: 410);
        }

        if ($account->email_verified_at !== null) {
            return $this->page('verified', ['email' => $account->email]);
        }

        $tries = 'verify-email-page:'.$account->id;

        if (RateLimiter::tooManyAttempts($tries, self::TRIES)) {
            return $this->confirmPage($request, $account, 'That was the wrong password too many times. Try again in an hour, or set a new password from the sign-in screen.', 429);
        }

        if (blank($account->password) || ! Hash::check((string) $request->input('password'), $account->password)) {
            RateLimiter::hit($tries, 3600);

            return $this->confirmPage($request, $account, 'That is not the password for this account. Type the one you sign in with.', 422);
        }

        RateLimiter::clear($tries);

        if (! $this->verification->confirm($account, $hash)) {
            return $this->page('expired', status: 410);
        }

        return $this->page('verified', ['email' => $account->email]);
    }

    /**
     * The account, if the link is genuine and was sent to the address the
     * account still has. A link to an address it has since moved from is as
     * dead as an expired one.
     */
    private function find(Request $request, string $id, string $hash): ?User
    {
        if (! $request->hasValidRelativeSignature()) {
            return null;
        }

        $user = User::find($id);

        return $user !== null && hash_equals(EmailVerification::hashFor($user->email), $hash) ? $user : null;
    }

    private function confirmPage(Request $request, User $account, ?string $error = null, int $status = 200): Response
    {
        return $this->page('confirm', [
            'email' => $account->email,
            'action' => $request->getRequestUri(),
            'error' => $error,
        ], $status);
    }

    /** @param  array<string, mixed>  $with */
    private function page(string $state, array $with = [], int $status = 200): Response
    {
        return response()->view('account-link', [
            'kind' => 'verify',
            'state' => $state,
            'console' => rtrim((string) config('app.console_url'), '/'),
            ...$with,
        ], $status);
    }
}
