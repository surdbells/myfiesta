<?php

namespace App\Http\Controllers;

use App\Models\WaitlistEntry;
use Illuminate\Http\Response;

/**
 * Leaving a waitlist from the link in its email.
 *
 * A page with a button rather than a link that acts on arrival: mail
 * scanners follow links, and one that removed people on a GET would empty
 * waitlists before anybody read them.
 */
class WaitlistLeaveController extends Controller
{
    public function show(string $token): Response
    {
        $entry = WaitlistEntry::with('event')->where('token', $token)->first();

        return response()->view('waitlist-leave', [
            'state' => $entry === null ? 'unknown' : ($entry->status === 'left' ? 'done' : 'confirm'),
            'entry' => $entry,
            'token' => $token,
        ]);
    }

    public function store(string $token): Response
    {
        $entry = WaitlistEntry::with('event')->where('token', $token)->first();

        $entry?->update(['status' => 'left']);

        return response()->view('waitlist-leave', [
            'state' => $entry === null ? 'unknown' : 'done',
            'entry' => $entry,
            'token' => $token,
        ]);
    }
}
