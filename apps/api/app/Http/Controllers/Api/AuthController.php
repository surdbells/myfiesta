<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Images\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Signing in.
 *
 * The token's abilities are decided here, from what the account actually is —
 * never from what the client asks for. The previous platform handed out a
 * permanent session token to anyone who could name an organizer's email
 * address, with no password step at all, and that token was the whole of its
 * authentication.
 */
class AuthController extends Controller
{
    public function login(Request $request, ImageStore $images): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:64'],
        ]);

        // Keyed on address and origin together, so one attacker cannot lock a
        // real person out of their own account by guessing at it.
        $key = 'login:'.strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minutes.',
            ])->status(429);
        }

        // However either was typed: an account made at a checkout before
        // addresses were kept in lower case is still the one signed in to.
        $user = User::whereRaw('lower(email) = ?', [strtolower(trim($credentials['email']))])->first();

        // One message for both causes. Distinguishing them turns this endpoint
        // into a way to enumerate who holds an account.
        if ($user === null
            || $user->password === null
            || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 900);

            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        RateLimiter::clear($key);

        $abilities = $this->abilitiesFor($user);

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken(
                $credentials['device'] ?? 'web',
                $abilities,
                // Expiring, unlike the previous platform's permanent tokens.
                // A lost phone stops being a standing grant.
                now()->addDays(30),
            )->plainTextToken,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                // So the phone's home screen greets with a face and the right
                // time of day from the first screen after signing in, rather
                // than after a second request.
                'avatar_url' => $images->avatarUrl($user),
                'timezone' => $user->timezone,
            ],
            'abilities' => $abilities,
            'organizations' => $user->organizations->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'role' => $o->pivot->role,
                // The resolved capability set. The client mirrors this rather
                // than deriving it from the role a second time — which is how
                // the two drifted before.
                'permissions' => $user->permissionsIn($o->id),
            ])->values(),
        ]);
    }

    /**
     * Everyone can hold tickets; membership of an organization is what adds
     * the organizer ability.
     *
     * Door is never granted here — it is issued per event, by an organizer,
     * for one night.
     *
     * @return list<string>
     */
    private function abilitiesFor(User $user): array
    {
        return $user->tokenAbilities();
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request, ImageStore $images): JsonResponse
    {
        $user = $request->user()->load('organizations');

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            // Whether the address is proved. Publishing, asking to be paid and
            // changing where payouts go wait for it (verified.email); without
            // this the console and the phone learnt it only from that refusal,
            // after somebody had already pressed the button.
            'email_verified' => $user->email_verified_at !== null,
            // The rest of what the profile form edits. Without them a form
            // opened on a phone showed the number box empty, so saving it as
            // it looked either kept a number nobody could see or, once empty
            // was taken to mean "none", quietly threw it away.
            'phone' => $user->phone,
            'timezone' => $user->timezone,
            // The photo, or null for initials. Asked for again when the phone
            // opens, so one changed on another phone turns up here too.
            'avatar_url' => $images->avatarUrl($user),
            'organizations' => $user->organizations->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'role' => $o->pivot->role,
                // The resolved capability set. The client mirrors this rather
                // than deriving it from the role a second time — which is how
                // the two drifted before.
                'permissions' => $user->permissionsIn($o->id),
                'verified' => $o->isVerified(),
            ])->values(),
        ]);
    }
}
