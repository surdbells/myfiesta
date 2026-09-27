<?php

namespace App\Http\Middleware;

use App\Services\Accounts\EmailVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Some things wait until the account's address is proved.
 *
 * Applied as `verified.email` to publishing an event, asking to be paid, and
 * changing where payouts go: the actions that put a name in front of the
 * public or move money, which are what an account made under somebody else's
 * address would be for. Everything else works unproved, and buying a ticket
 * needs no account at all.
 *
 * Refusing sends the link as well, within the same hourly allowance the
 * "send it again" button draws on. The person asking is told where it went
 * and to try again once it is opened, which works in any client without that
 * client knowing this state exists.
 */
class EnsureEmailIsVerified
{
    public function __construct(private readonly EmailVerification $verification) {}

    /**
     * @param  string|null  $onlyForStatus  check only a request asking for this
     *                                      status, as `verified.email:published`
     *                                      does on the publish route: taking an
     *                                      event off sale never waits for an
     *                                      address, because an account that
     *                                      cannot prove one must still be able
     *                                      to stop selling.
     */
    public function handle(Request $request, Closure $next, ?string $onlyForStatus = null): Response
    {
        $user = $request->user();

        if ($user === null || $user->email_verified_at !== null) {
            return $next($request);
        }

        if ($onlyForStatus !== null && $request->input('status') !== $onlyForStatus) {
            return $next($request);
        }

        $sent = $this->verification->send($user);

        return response()->json([
            'message' => $sent
                ? "Confirm your email address first. We have sent a link to {$user->email} — open it, then try again."
                : "Confirm your email address first. Open the link we sent to {$user->email}, then try again.",
            'code' => 'email_unverified',
        ], 403);
    }
}
