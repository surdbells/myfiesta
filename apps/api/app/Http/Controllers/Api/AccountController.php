<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Mail\EmailChangeAddressInUse;
use App\Mail\EmailChangeConfirm;
use App\Mail\EmailChanged;
use App\Mail\EmailChangeRequested;
use App\Models\EmailChange;
use App\Models\EmailPreference;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Models\OrganizationInvitation;
use App\Services\Door\DoorPasses;
use App\Services\Team\TeamService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Becoming an organizer, and staying one.
 *
 * Until this existed there was no way onto the platform at all — every account
 * in the rebuild was inserted by hand in a console. An engine nobody can sign
 * up for is not a product, however well tested it is.
 *
 * Registration creates a person and an organization together. An organizer with
 * no organization has nowhere to put an event, and asking for the venue or
 * promoter name at the moment somebody is deciding to join is cheaper than a
 * second empty-state screen telling them to create one.
 */
class AccountController extends Controller
{
    /**
     * The rule for a new password, in one place.
     *
     * Length first: it is the only property that reliably resists an offline
     * guess, and complexity rules mostly produce Password1! — which is in
     * every list an attacker already owns.
     */
    private function passwordRule(): PasswordRule
    {
        return PasswordRule::min(10)->letters()->numbers();
    }

    public function register(Request $request): JsonResponse
    {
        // Keyed on origin. Registration is the cheapest way to fill a users
        // table with junk, and the only signal available before an account
        // exists is where the request came from.
        $key = 'register:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-ups from here. Try again shortly.',
            ])->status(429);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
            /*
             * Left out by somebody who is going out rather than putting
             * something on.
             *
             * The phone app's sign-up is for people who bought as guests and
             * want their tickets to follow them, and asking them to name an
             * events page is asking a question about a business they do not
             * have. Not needed when joining somebody else's organization by
             * invitation either.
             */
            'organization' => ['required_without_all:invitation,attendee', 'nullable', 'string', 'max:120'],
            'invitation' => ['nullable', 'string', 'max:64'],
            /*
             * Declared, not inferred from a missing field.
             *
             * Without it, a console sign-up that lost its organization field
             * on the way would quietly make an attendee account and drop
             * somebody into a console with nothing in it, which looks like the
             * console is broken rather than like a form that failed.
             */
            'attendee' => ['nullable', 'boolean'],
            'device' => ['nullable', 'string', 'max:64'],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
            'organization.required_without_all' => 'What should we call your events page?',
        ]);

        $email = Str::lower(trim($data['email']));

        /*
         * Joining by invitation: the account is made for the address the
         * invitation went to, and nothing else. Checked before anything is
         * created, so a wrong address leaves no half-made account behind.
         */
        $invitation = filled($data['invitation'] ?? null) ? OrganizationInvitation::findByToken($data['invitation']) : null;

        if (filled($data['invitation'] ?? null)) {
            if ($invitation === null || $invitation->state() !== 'open') {
                throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid. Ask for a new one.']);
            }

            if (Str::lower($invitation->email) !== $email) {
                throw ValidationException::withMessages(['email' => "This invitation was sent to {$invitation->email}. Use that address."]);
            }
        }

        /*
         * An address already in use is not reported as such.
         *
         * Saying "that email is taken" turns registration into a way to test
         * whether somebody holds an account here — which for a platform whose
         * organizers are named venues and promoters is a competitive
         * intelligence tool. The person who genuinely owns it gets an email
         * telling them so, which is the only channel that proves ownership.
         */
        if (User::where('email', $email)->exists()) {
            RateLimiter::hit($key, 3600);

            return response()->json([
                'message' => 'Check your email to finish setting up your account.',
                'pending' => true,
            ], 202);
        }

        RateLimiter::hit($key, 3600);

        $user = DB::transaction(function () use ($data, $email, $invitation) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $email,
                // Plain, not hashed. The model casts password => 'hashed', so
                // hashing here would hash the hash and nothing would ever
                // match — a failure that only shows up at the next sign-in.
                'password' => $data['password'],
            ]);

            if ($invitation !== null) {
                app(TeamService::class)->accept($invitation, $user->load('organizations'));

                return $user;
            }

            // An attendee account: no organization, and so no organizer
            // ability when the token is minted below.
            if (filled($data['attendee'] ?? null) || blank($data['organization'] ?? null)) {
                return $user;
            }

            $organization = Organization::create([
                'name' => trim($data['organization']),
                'slug' => $this->slugFor($data['organization']),
            ]);

            // Owner, not manager. The person who created it is the only one
            // who can hand that over, and an organization whose owner is a
            // support ticket away is one nobody can actually run.
            $organization->members()->attach($user->id, [
                'id' => (string) Str::uuid(),
                'role' => Role::Owner->value,
                'accepted_at' => now(),
            ]);

            return $user;
        });

        $user->load('organizations');

        // The same rule signing in uses, rather than a second list that grants
        // the organizer screens to an account with no organization behind them.
        $abilities = $user->tokenAbilities();

        return response()->json([
            'token' => $user->createToken($data['device'] ?? 'web', $abilities, now()->addDays(30))->plainTextToken,
            'user' => ['name' => $user->name, 'email' => $user->email],
            // Said, as signing in says it. The phone decides which half of the
            // app to open from this, and without it a brand-new organizer was
            // shown the attendee's.
            'abilities' => $abilities,
            'organizations' => $user->organizations->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'role' => $o->pivot->role,
                // Same shape as sign-in, so a session started by registering
                // and one started by signing in are indistinguishable to the
                // console. A payload that differs by entry point is a bug
                // waiting for whichever path is tested less.
                'permissions' => $user->permissionsIn($o->id),
            ])->values(),
        ], 201);
    }

    /**
     * Ask for a reset link.
     *
     * Always the same answer, whether or not the address is known. This is the
     * other half of not confirming who holds an account, and it is the half
     * that usually gets forgotten because the honest version is easier to
     * write.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $key = 'forgot:'.Str::lower($data['email']).'|'.$request->ip();

        // Silently throttled rather than refused, so the rate limit itself
        // cannot be used to tell a known address from an unknown one.
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 900);

            Password::sendResetLink(['email' => Str::lower(trim($data['email']))]);
        }

        return response()->json([
            'message' => 'If that address has an account, a reset link is on its way.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower(trim($data['email'])),
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $data['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                /*
                 * Every existing token is revoked.
                 *
                 * A password reset is what somebody does after losing a phone
                 * or suspecting a break-in. Leaving the old sessions alive
                 * means the reset changes nothing for the person it was
                 * protecting them from.
                 */
                $user->tokens()->delete();

                // Including door links this account made that nobody has
                // opened yet, which deleting tokens alone would leave working.
                app(DoorPasses::class)->endIssuedBy($user);

                // And a move to a new address that is still waiting. This is
                // what the warning sent to the old address tells somebody to
                // do if the move was not theirs, and it would not help them if
                // the link at the other end still worked.
                EmailChange::where('user_id', $user->id)->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'That reset link has expired. Ask for a new one.',
            ]);
        }

        return response()->json(['message' => 'Your password has been changed. Sign in again.']);
    }

    /** Name, phone, and the zone dates are shown in. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        // Email is absent on purpose. Changing the address an account is
        // reached at has to be confirmed at the new address before it takes
        // effect, or it is a way to take an account over from a borrowed
        // laptop — requestEmailChange() below is that flow.
        $user->update($data);

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'timezone' => $user->timezone,
        ]);
    }

    public function changePassword(Request $request, DoorPasses $doorPasses): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        if ($user->password === null || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Everything except the session doing the changing. Somebody changing
        // their password from a laptop should not be signed out of it, and
        // should be signed out everywhere else.
        $current = $request->user()->currentAccessToken();
        $user->tokens()->where('id', '!=', $current->id)->delete();

        // Door passes are other devices too, and a link made but not yet
        // opened is one that has not signed in yet.
        $passes = $doorPasses->endIssuedBy($user);

        // A move to a new address still waiting is cancelled too. Somebody
        // who was warned that their address is about to change is told to do
        // exactly this, and the link at the other end must stop working when
        // they do. Saying so gives nothing away about the address: asking
        // leaves a change waiting whether or not it already had an account.
        $cancelled = EmailChange::where('user_id', $user->id)->delete() > 0;

        return response()->json([
            'message' => ($passes > 0
                ? 'Password changed. Other devices have been signed out, and door passes you made have stopped working.'
                : 'Password changed. Other devices have been signed out.')
                .($cancelled ? ' The new email address you asked for was cancelled too — ask again if you still want it.' : ''),
            'door_passes_ended' => $passes,
        ]);
    }

    /**
     * Ask to move the account to a new address.
     *
     * Nothing changes here. A link goes to the new address and the account
     * moves only when it is opened — reading that inbox is the proof. The
     * current password is asked for as well, because the person holding a
     * borrowed, unlocked laptop is signed in but does not know it.
     *
     * The answer is the same whether or not the new address already has an
     * account. Anything else would make this a way to test who holds one here,
     * which registration and password reset are both careful not to be.
     */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();

        // Keyed on the account, and counted whether or not the password was
        // right: otherwise this is an unthrottled way to guess it from a
        // session somebody left open. Each attempt also sends two emails.
        $key = 'email-change:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minutes.',
            ])->status(429);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'current_password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($data['email']));

        if ($email === Str::lower($user->email)) {
            throw ValidationException::withMessages([
                'email' => 'That is the address you already use.',
            ]);
        }

        RateLimiter::hit($key, 3600);

        if ($user->password === null || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        // Soft-deleted accounts included: the address is still theirs as far
        // as the unique index is concerned. Compared lowercased, because
        // imported accounts kept their addresses as they were typed.
        $taken = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists();

        $token = Str::random(48);

        // A stored token has an id to keep; anything else — a first-party
        // session, a test's stand-in — just means nothing is kept.
        $current = $request->user()->currentAccessToken();
        $asking = $current instanceof PersonalAccessToken && $current->exists ? $current->getKey() : null;

        DB::transaction(function () use ($user, $email, $token, $asking) {
            // Whatever was asked for before stops working, whether or not this
            // one can go ahead — if an earlier link survived only when the new
            // address was taken, that would give the answer away.
            EmailChange::where('user_id', $user->id)->delete();

            /*
             * Stored whether or not the address can be used, for the same
             * reason.
             *
             * Other screens can tell whether a change is waiting — a new
             * password says it cancelled one — so if only a free address left
             * one behind, asking and then changing the password would say
             * whose address it was. When the address is taken, its token is
             * sent nowhere, so nothing can ever open this one; and opening a
             * link checks the address again in any case.
             */
            EmailChange::create([
                'user_id' => $user->id,
                'email' => $email,
                'token_hash' => EmailChange::hashToken($token),
                'requested_by_token_id' => $asking,
                'expires_at' => now()->addMinutes(EmailChange::EXPIRES_MINUTES),
            ]);
        });

        /*
         * Two emails either way, so the two cases look alike from outside.
         *
         * The new address gets the link, or — when it already has an account —
         * a note saying somebody tried, with nothing in it that completes
         * anything. The address the account uses now is told in both cases,
         * which is the owner's warning if the request was not theirs.
         */
        Mail::to($email)->send($taken ? new EmailChangeAddressInUse : new EmailChangeConfirm($token));
        Mail::to($user->email)->send(new EmailChangeRequested($user, $email));

        return response()->json([
            'message' => "Check {$email} for a link to confirm it. Nothing changes until it is opened, and it works for an hour.",
        ], 202);
    }

    /**
     * Open the link sent to the new address.
     *
     * Unauthenticated, like a password reset: the link is the credential, and
     * it is usually opened in a mail app on a phone where nobody is signed in
     * to anything. Single use — the waiting change is gone once it has worked.
     */
    public function confirmEmailChange(Request $request, DoorPasses $doorPasses): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ]);

        try {
            $outcome = DB::transaction(function () use ($data, $doorPasses) {
                $change = EmailChange::query()
                    ->where('token_hash', EmailChange::hashToken($data['token']))
                    ->lockForUpdate()
                    ->first();

                if ($change === null) {
                    return 'invalid';
                }

                $user = $change->user;

                if ($user === null || $change->hasExpired()) {
                    $change->delete();

                    return 'invalid';
                }

                // Somebody signed up with it, or moved to it, since the link
                // was sent.
                if (User::withTrashed()->whereRaw('lower(email) = ?', [$change->email])->whereKeyNot($user->id)->exists()) {
                    $change->delete();

                    return 'taken';
                }

                $old = $user->email;

                // Verified: opening the link is exactly what proves it.
                $user->forceFill([
                    'email' => $change->email,
                    'email_verified_at' => now(),
                ])->save();

                $change->delete();

                $this->carryOptOuts($old, $change->email);

                /*
                 * Tickets the account holds follow it.
                 *
                 * Reminders and organizers' messages go to the address on the
                 * ticket, not the account's, so without this the old inbox —
                 * often the one that was lost or broken into — would keep
                 * getting them. Only tickets that carry the old address: one
                 * bought for a different address went where the buyer chose.
                 *
                 * And not one a host issued from a guest list. Those are found
                 * again by the address the host typed; moved, the guest's next
                 * reply would find no tickets and issue a second set.
                 */
                Ticket::query()
                    ->where('owner_user_id', $user->id)
                    ->whereRaw('lower(owner_email) = ?', [Str::lower($old)])
                    ->whereNotExists(fn ($guests) => $guests->from('guests')
                        ->whereColumn('guests.event_id', 'tickets.event_id')
                        ->whereRaw('lower(guests.email) = lower(tickets.owner_email)'))
                    ->update(['owner_email' => $change->email]);

                // A reset link sent to the old address still works for an hour,
                // and it is keyed on the address, not the account. Somebody who
                // signed up with that address next would have their password
                // settable by whoever still held it.
                DB::table('password_reset_tokens')->where('email', $old)->delete();

                /*
                 * Everything except the session that asked, as a new password
                 * does.
                 *
                 * Moving an account is what somebody does when an old address
                 * has been lost or compromised, and the session they asked from
                 * is the one that proved the password. Everything else is
                 * signed out — including door links this account made, which
                 * are other devices too.
                 */
                $user->tokens()
                    ->when($change->requested_by_token_id, fn ($tokens, $id) => $tokens->where('id', '!=', $id))
                    ->delete();

                $passes = $doorPasses->endIssuedBy($user);

                return ['user' => $user, 'old' => $old, 'passes' => $passes];
            });
        } catch (UniqueConstraintViolationException) {
            // The same race as above, lost at the last moment.
            $outcome = 'taken';
        }

        if ($outcome === 'taken') {
            throw ValidationException::withMessages([
                'token' => 'That address now belongs to another account, so yours cannot move to it.',
            ]);
        }

        if ($outcome === 'invalid') {
            // One answer for a link that has expired, has been used, was
            // replaced by a newer one, or was never real. None of them can be
            // put right from here, and all of them are put right the same way.
            throw ValidationException::withMessages([
                'token' => 'That link has expired or has already been used. Ask for a new one from your account.',
            ]);
        }

        ['user' => $user, 'old' => $old, 'passes' => $passes] = $outcome;

        Mail::to($old)->send(new EmailChanged($user, $old));

        return response()->json([
            'message' => $passes > 0
                ? "Your account now uses {$user->email}. Other devices have been signed out, and door passes you made have stopped working."
                : "Your account now uses {$user->email}. Other devices have been signed out.",
            'email' => $user->email,
            'door_passes_ended' => $passes,
        ]);
    }

    /**
     * What the old address said no to, the new one says no to as well.
     *
     * Opt-outs are kept against an address rather than an account, because
     * most people who use them bought as guests and have no account. Left
     * behind, somebody who stopped announcements would start getting them
     * again at the address they moved to, having said nothing to change that.
     *
     * Only a no is carried: whatever the new address had already said no to
     * stays said. The old address keeps its own row — a ticket bought for it,
     * or a guest-list invitation, can still send mail there, and its no still
     * stands.
     */
    private function carryOptOuts(string $from, string $to): void
    {
        $was = EmailPreference::where('email', EmailPreference::normalise($from))->first();

        if ($was === null || ($was->wantsReminders() && $was->wantsMarketing())) {
            return;
        }

        $now = EmailPreference::forEmail($to);

        $now->forceFill([
            'reminders_opted_out_at' => $now->reminders_opted_out_at ?? $was->reminders_opted_out_at,
            'marketing_opted_out_at' => $now->marketing_opted_out_at ?? $was->marketing_opted_out_at,
        ])->save();
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
