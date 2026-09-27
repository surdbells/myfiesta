<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounts\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Send it again": a fresh link to prove the signed-in account's address.
 *
 * About the account asking, and only ever to the address it already has, so
 * saying where the link went tells nobody anything they did not know.
 */
class EmailVerificationController extends Controller
{
    public function __invoke(Request $request, EmailVerification $verification): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return response()->json([
                'message' => 'Your email address is already confirmed.',
                'verified' => true,
            ]);
        }

        if (! $verification->send($user)) {
            return response()->json([
                'message' => 'A link went to '.$user->email.' a moment ago. Check that inbox, including spam, or ask again in '
                    .$verification->minutesUntilNext($user).' minutes.',
                'verified' => false,
            ], 429);
        }

        return response()->json([
            'message' => "We sent a link to {$user->email}. Open it to confirm the address.",
            'verified' => false,
        ], 202);
    }
}
