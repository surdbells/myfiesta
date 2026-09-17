<?php

namespace App\Http\Controllers;

use App\Models\OrganizationFollow;
use Illuminate\Http\Response;

/**
 * Stopping following an organizer, from the link in their announcement.
 *
 * A page with a button rather than a link that acts on arrival: mail clients
 * and security scanners follow links in messages, and an endpoint that
 * unfollows on GET unfollows people who never clicked.
 *
 * No account needed. Most people who follow an organizer bought a ticket as a
 * guest, so a way out behind a sign-in is not a way out.
 */
class FollowLeaveController extends Controller
{
    public function show(string $token): Response
    {
        $follow = OrganizationFollow::with('organization')->where('token', $token)->first();

        return response()->view('follow-leave', [
            'state' => $follow === null ? 'unknown' : 'confirm',
            'follow' => $follow,
            'token' => $token,
        ], $follow === null ? 404 : 200);
    }

    public function store(string $token): Response
    {
        $follow = OrganizationFollow::with('organization')->where('token', $token)->first();

        // Read the name before the row goes, so the page can still say who.
        $name = $follow?->organization?->name;

        $follow?->delete();

        return response()->view('follow-leave', [
            'state' => $follow === null ? 'unknown' : 'done',
            'follow' => null,
            'name' => $name,
            'token' => $token,
        ], $follow === null ? 404 : 200);
    }
}
