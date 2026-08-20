<?php

namespace App\Http\Controllers;

use App\Models\EmailPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Getting out, in one click and without an account.
 *
 * CASL requires the way out to work for at least ten days and to take no more
 * than two clicks; guest checkout means most people who need it have no
 * account, so anything behind a login is not a way out at all.
 *
 * The token is the whole credential. That is deliberate: it identifies nothing
 * on its own, so a link sitting in forwarded mail or a corporate scanner leaks
 * nothing about who it belongs to, and the worst somebody can do with a stolen
 * one is stop emails the owner can turn back on.
 */
class UnsubscribeController extends Controller
{
    /**
     * The page somebody lands on.
     *
     * A GET changes nothing, on purpose. Mail clients and security scanners
     * prefetch links in messages, and an endpoint that unsubscribes on GET
     * unsubscribes people who never clicked — silently, and in numbers nobody
     * notices until the send volume drops.
     */
    public function show(Request $request, string $token): Response
    {
        $preference = EmailPreference::where('token', $token)->first();

        if (! $preference) {
            return response()->view('unsubscribe', [
                'state' => 'unknown',
                'token' => null,
            ], 404);
        }

        return response()->view('unsubscribe', [
            'state' => $preference->wantsReminders() ? 'confirm' : 'already',
            'token' => $token,
        ]);
    }

    /**
     * The click that actually does it.
     *
     * Also what a mail client posts when it offers its own unsubscribe button:
     * the List-Unsubscribe-Post header names this endpoint, and Gmail and
     * Outlook post to it directly. Those requests carry no CSRF token and no
     * session, which is why this route is excluded from CSRF — the token in the
     * URL is the authorisation.
     */
    public function store(Request $request, string $token): Response
    {
        $preference = EmailPreference::where('token', $token)->first();

        if (! $preference) {
            return response()->view('unsubscribe', [
                'state' => 'unknown',
                'token' => null,
            ], 404);
        }

        if ($preference->wantsReminders()) {
            $preference->update(['reminders_opted_out_at' => now()]);
        }

        return response()->view('unsubscribe', [
            'state' => 'done',
            'token' => $token,
        ]);
    }

    /** Changed their mind, from the same page. */
    public function resubscribe(Request $request, string $token): Response
    {
        $preference = EmailPreference::where('token', $token)->firstOrFail();

        $preference->update(['reminders_opted_out_at' => null]);

        return response()->view('unsubscribe', [
            'state' => 'resubscribed',
            'token' => $token,
        ]);
    }
}
