<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\Terms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in account and the terms: whether it has agreed to the words in
 * force now, and agreeing to them.
 *
 * Signing up asks, and so does a checkout that knows its buyer. That leaves
 * out the accounts made before signing up asked, the ones brought over from
 * the previous platform, and anybody who only ever runs events and never
 * buys a ticket — so the console and the phone's organizer screens ask them
 * here, with the same unticked box and the same three pages.
 *
 * Only the version and the moment are kept, as everywhere else (see Terms).
 */
class TermsController extends Controller
{
    public function show(Request $request, Terms $terms): JsonResponse
    {
        return response()->json($this->standing($request->user(), $terms));
    }

    /**
     * The box, ticked.
     *
     * Agreeing again to words already agreed to changes nothing: the moment
     * kept is when this account first agreed to them, which is the answer
     * anybody asking later needs, not when a second tab caught up.
     */
    public function accept(Request $request, Terms $terms): JsonResponse
    {
        $request->validate(
            ['accept_terms' => ['accepted']],
            ['accept_terms.accepted' => Terms::REFUSAL],
        );

        $user = $request->user();

        if (! $terms->acceptedBy($user)) {
            $terms->recordFor($user);
        }

        return response()->json($this->standing($user->fresh() ?? $user, $terms));
    }

    /** @return array{current: string, accepted: bool, accepted_version: string|null, accepted_at: string|null} */
    private function standing(User $user, Terms $terms): array
    {
        return [
            'current' => $terms->current(),
            'accepted' => $terms->acceptedBy($user),
            'accepted_version' => $user->terms_version,
            'accepted_at' => $user->terms_accepted_at?->toIso8601String(),
        ];
    }
}
