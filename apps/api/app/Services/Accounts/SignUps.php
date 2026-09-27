<?php

namespace App\Services\Accounts;

use App\Enums\Role;
use App\Mail\SignUpAddressInUse;
use App\Mail\SignUpConfirm;
use App\Models\Organization;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Signing up, which ends in the inbox rather than on the form.
 *
 * The form's answer is the same for every address — "check your email" — so
 * it cannot be used to learn who holds an account here. The email is where
 * the two cases part: a link that makes the account, or a note that there
 * already is one. Only whoever reads that inbox ever finds out which.
 *
 * And nothing is made until the link is opened. An account made on the spot
 * could be made for somebody else's address, and every ticket later bought
 * as a guest with that address would land in it.
 */
class SignUps
{
    /**
     * How many sign-ups waiting for one address a link tries a password
     * against: its own, then the newest of the rest.
     *
     * Each try is a password hash checked, a quarter of a second at
     * production's cost, so the list is kept short. It only has to reach the
     * owner's own sign-up, which is among the newest because the owner opens
     * the link soon after asking for it.
     */
    private const TRIED_PER_LINK = 5;

    /**
     * Take a sign-up, and send whichever email its address calls for.
     *
     * Both branches do the same work in the same order — the hash is made
     * either way, one email goes either way, synchronously either way — so
     * that how long the answer took says no more than the answer does.
     *
     * With $sendEmail false the address has had its emails for the hour. The
     * sign-up is kept anyway, with no email of its own: the links already in
     * that inbox open it (see opens()). Dropping it instead would let a
     * stranger who used up the hour's emails stop the owner signing up at
     * all, for as long as the stranger kept at it.
     *
     * @param  string  $password  already hashed; the caller hashed it whatever happens next
     */
    public function begin(string $email, string $name, string $password, ?string $organization, bool $sendEmail = true): void
    {
        if ($this->addressIsTaken($email)) {
            if ($sendEmail) {
                Mail::to($email)->send(new SignUpAddressInUse);
            }

            return;
        }

        // Tidied as they go by, rather than by a scheduled job: a day-old
        // sign-up nobody opened is worth nothing and holds a password hash.
        PendingRegistration::where('expires_at', '<', now())->delete();

        $pending = PendingRegistration::create([
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'organization' => filled($organization) ? $organization : null,
            'expires_at' => now()->addHours(PendingRegistration::EXPIRES_HOURS),
        ]);

        if ($sendEmail) {
            Mail::to($email)->send(new SignUpConfirm($this->url($pending)));
        }
    }

    /**
     * The sign-up a link opens with this password, if any.
     *
     * Not only the one the link was sent for: any sign-up still waiting for
     * the same address. The link proves the inbox and the password proves the
     * form, and both are about that address whichever sign-up sent the email.
     * It matters when a stranger has used up the address's emails for the
     * hour — the owner's sign-up then waits with no email of its own, and one
     * of the stranger's links, which only the owner can read, is what opens
     * it.
     */
    public function opens(PendingRegistration $link, string $password): ?PendingRegistration
    {
        if ($password === '') {
            return null;
        }

        $candidates = PendingRegistration::where('email', $link->email)
            ->whereKeyNot($link->getKey())
            ->where('expires_at', '>', now())
            ->latest()
            ->limit(self::TRIED_PER_LINK - 1)
            ->get()
            ->prepend($link);

        return $candidates->first(fn (PendingRegistration $pending) => Hash::check($password, $pending->password));
    }

    /**
     * Where the link lands: a page on this API, signed, with the sign-up's id.
     *
     * Built from APP_URL, never from the request. The request's Host is
     * whatever the caller wrote, and a link built from it would send somebody
     * else's confirmation to a site of the caller's choosing.
     */
    public function url(PendingRegistration $pending): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
            'sign-up.show',
            $pending->expires_at,
            ['registration' => $pending->id],
            absolute: false,
        );
    }

    /**
     * Open the account the link was for.
     *
     * Three ways it can go. Nobody holds the address: a new account. Somebody
     * bought tickets with it and never signed up: that account, which is
     * theirs, gets the password they chose — and the tickets are already in
     * it. Somebody signed up with it since: nothing, because it is theirs.
     *
     * The address is verified either way it opens: opening this link is
     * exactly what proves it.
     *
     * @return User|'taken'|'expired'
     */
    public function complete(string $id): User|string
    {
        try {
            return DB::transaction(function () use ($id) {
                $pending = PendingRegistration::whereKey($id)->lockForUpdate()->first();

                if ($pending === null || $pending->hasExpired()) {
                    $pending?->delete();

                    return 'expired';
                }

                $user = User::withTrashed()
                    ->whereRaw('lower(email) = ?', [$pending->email])
                    ->lockForUpdate()
                    ->first();

                if ($user !== null && ($user->trashed() || ! $user->isUnclaimed())) {
                    PendingRegistration::where('email', $pending->email)->delete();

                    return 'taken';
                }

                $user ??= new User(['email' => $pending->email]);

                // Hashed already, and the model's cast leaves a hash as it is.
                $user->forceFill([
                    'name' => $pending->name,
                    'password' => $pending->password,
                    'email_verified_at' => now(),
                ])->save();

                if (! $pending->isAttendee()) {
                    $this->openOrganization($user, $pending->organization);
                }

                // Every other sign-up waiting on this address is spent too:
                // the address has an account now, and a second link opening
                // would only find that out later.
                PendingRegistration::where('email', $pending->email)->delete();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two links for one address, opened at the same moment.
            return 'taken';
        }
    }

    /**
     * An events page, owned by whoever opened it.
     *
     * Owner, not manager. The person who created it is the only one who can
     * hand that over, and an organization whose owner is a support ticket
     * away is one nobody can actually run.
     */
    public function openOrganization(User $user, string $name): Organization
    {
        $organization = Organization::create([
            'name' => trim($name),
            'slug' => $this->slugFor($name),
        ]);

        $organization->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        return $organization;
    }

    /**
     * Whether this address already belongs to somebody who can sign in.
     *
     * An account made by buying tickets as a guest does not count: it has no
     * password, and signing up is how its owner gets into it. A closed
     * account does count — its address is still its own as far as the unique
     * index is concerned. Compared lowercased, because imported accounts kept
     * their addresses as they were typed.
     */
    public function addressIsTaken(string $email): bool
    {
        $user = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();

        return $user !== null && ($user->trashed() || ! $user->isUnclaimed());
    }

    private function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'organizer';
        $slug = $base;
        $n = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
