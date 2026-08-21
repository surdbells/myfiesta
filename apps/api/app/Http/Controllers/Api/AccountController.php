<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

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
            'organization' => ['required', 'string', 'max:120'],
            'device' => ['nullable', 'string', 'max:64'],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
            'organization.required' => 'What should we call your events page?',
        ]);

        $email = Str::lower(trim($data['email']));

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

        $user = DB::transaction(function () use ($data, $email) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $email,
                // Plain, not hashed. The model casts password => 'hashed', so
                // hashing here would hash the hash and nothing would ever
                // match — a failure that only shows up at the next sign-in.
                'password' => $data['password'],
            ]);

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

        return response()->json([
            'token' => $user->createToken(
                $data['device'] ?? 'web',
                [TokenAbility::Attendee->value, TokenAbility::Organizer->value],
                now()->addDays(30),
            )->plainTextToken,
            'user' => ['name' => $user->name, 'email' => $user->email],
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
        // laptop. That flow is worth building properly rather than here.
        $user->update($data);

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'timezone' => $user->timezone,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
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

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
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
