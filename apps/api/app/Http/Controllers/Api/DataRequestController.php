<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Requests;
use App\Services\PersonalData\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Send me what you hold about me", or "forget me".
 *
 * Open to anybody with an address, because guest checkout means most people
 * holding a ticket have no account to ask from. Nothing happens until the
 * link emailed to that address comes back.
 *
 * And "delete my account", from inside the phone app or the console, which
 * the App Store requires of an app that makes accounts. The same erasure,
 * asked for by somebody signed in — see Requests::openForAccount for what
 * that changes about the proof.
 */
class DataRequestController extends Controller
{
    public function __construct(private readonly Requests $requests) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'kind' => ['required', Rule::in(['export', 'erasure'])],
        ]);

        $this->requests->open($data['kind'], $data['email'], $request->ip());

        // The same answer whoever asked, known address or not: anything else
        // turns this into a way to find out who has an account here.
        return response()->json([
            'message' => 'If that address is on myFiesta, a link is on its way to it. '
                .'The link is how we know the request is yours; nothing happens until you follow it.',
        ], 202);
    }

    /**
     * What deleting the signed-in account would do, before it is asked for.
     *
     * Shown beside the button: the organizations the person would leave, and
     * any they are the only owner of, which stop it. Better said here, with a
     * way to hand each one over, than after somebody has typed their password.
     */
    public function preview(Request $request, Eraser $eraser): JsonResponse
    {
        $user = $request->user()->load('organizations');
        $subject = new Subject($user->email, $user);
        $stranded = $eraser->stranded($subject)->pluck('id')->all();

        return response()->json([
            'email' => $user->email,
            // Decides the proof: a proved address is erased on the password,
            // any other is sent the privacy page's link first.
            'email_verified' => $user->email_verified_at !== null,
            'refused' => $eraser->refusal($subject),
            'organizations' => $user->organizations->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $organization->pivot->role,
                'only_owner' => in_array($organization->id, $stranded, true),
            ])->values(),
            // How long orders and the ledger outlive the person, with nobody's
            // name on them: the figure the explanation quotes.
            'kept_for_years' => (int) config('personal_data.retention.financial_records_years'),
            // Whether the audit trail names them: a refund sent, a price
            // changed, on somebody's team. Kept under the name they had then,
            // because nobody can edit that trail, us included — so the screen
            // says so here rather than promising a clean slate.
            'history_kept' => AuditLog::query()->where('actor_id', $user->id)->exists(),
        ]);
    }

    /**
     * Delete the signed-in account.
     *
     * The password is asked for, as it is before the address can be moved: a
     * phone left unlocked is signed in, and must not be enough to erase the
     * person it belongs to. Counted per account whether it was right or not,
     * or this would be an unthrottled way to guess it.
     */
    public function erase(Request $request, Eraser $eraser): JsonResponse
    {
        $user = $request->user();
        $key = 'account-erasure:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'current_password' => 'Too many attempts. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minutes.',
            ])->status(429);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        RateLimiter::hit($key, 3600);

        if ($user->password === null || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        // Refused before anything is opened: handing an organization over, or
        // having another administrator take staff access away, is something
        // they can do now, and the preview already said so.
        $refusal = $eraser->refusal(new Subject($user->email, $user));

        if ($refusal !== null) {
            return response()->json(['status' => 'refused', 'message' => $refusal], 409);
        }

        $erasure = $this->requests->openForAccount($user, $request->ip());

        return match ($erasure->status) {
            'completed' => response()->json([
                'status' => 'completed',
                'message' => 'Your account is deleted, and you are signed out everywhere. An email to '
                    .$erasure->email.' says what was kept, and why.',
            ]),
            // Only if somebody became the only owner in the moment between the
            // check above and the erasure. Recorded, like any refusal.
            'refused' => response()->json([
                'status' => 'refused',
                'message' => $erasure->outcome['refused'] ?? 'Your account could not be deleted yet.',
            ], 409),
            default => response()->json([
                'status' => 'pending',
                'message' => "We sent a link to {$erasure->email}. Your account is deleted when you open it and confirm; "
                    .'until then nothing changes. The link works for a day.',
            ], 202),
        };
    }
}
