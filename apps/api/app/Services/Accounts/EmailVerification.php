<?php

namespace App\Services\Accounts;

use App\Mail\VerifyEmailAddress;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * Proving an address on an account that already exists.
 *
 * A sign-up proves its address by opening the link that makes the account,
 * and an invitation by being opened from the inbox it went to. What is left
 * are the accounts made before either was true, and any that lose the mark —
 * this is how they earn it: a signed link to the address, opened.
 *
 * The link names the account and a hash of the address it was sent to, so
 * moving the account to another address in the meantime leaves it pointing at
 * nothing.
 */
class EmailVerification
{
    /** How long a link works. */
    public const EXPIRES_HOURS = 24;

    /** Links sent to one account, per hour, however they were asked for. */
    public const PER_HOUR = 3;

    /**
     * Send a link, unless enough have gone recently.
     *
     * Throttled per account, whichever way it was asked for — the button, or
     * a refused action sending one on its own — so neither can be used to
     * fill somebody's inbox.
     */
    public function send(User $user): bool
    {
        $key = 'verify-email:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        // During the request, not queued: the link is a working credential
        // for a day, and a queued email is a copy of it in the jobs table.
        Mail::to($user->email)->send(new VerifyEmailAddress($this->url($user)));

        return true;
    }

    /** Minutes until another link may be sent. */
    public function minutesUntilNext(User $user): int
    {
        return (int) ceil(RateLimiter::availableIn('verify-email:'.$user->id) / 60);
    }

    /**
     * Built from APP_URL, never from the request, for the reason SignUps::url()
     * gives.
     */
    public function url(User $user): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
            'email.verify.show',
            now()->addHours(self::EXPIRES_HOURS),
            ['user' => $user->id, 'hash' => self::hashFor($user->email)],
            absolute: false,
        );
    }

    /**
     * Mark the address proved, if the link was for the address the account
     * still has.
     */
    public function confirm(User $user, string $hash): bool
    {
        if (! hash_equals(self::hashFor($user->email), $hash)) {
            return false;
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return true;
    }

    public static function hashFor(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
