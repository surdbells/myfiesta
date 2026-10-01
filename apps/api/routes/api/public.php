<?php

// An organizer's page (socials, past events page by page) and the how-to videos.

use App\Http\Controllers\Api\HelpVideoController;
use App\Http\Controllers\Api\OrganizerController;
use Illuminate\Support\Facades\Route;

/*
 * An organizer's nights beyond the first page, twelve at a time: what "Show
 * more past events" asks for. Public like the page itself, and read the same
 * way (routes/api.php's $publicRead): the same answer for everybody, never
 * kept past the moment, so a night that has just ended moves across without
 * waiting on a cache.
 */
Route::get('/organizers/{slug}/events', [OrganizerController::class, 'events'])
    ->middleware(['cache.headers:public;max_age=0;must_revalidate', 'throttle:60,1']);

/*
 * The how-to videos on help/videos.
 *
 * The same list for everybody and changed only from the admin, so a browser
 * or the site's server render may keep it for five minutes (the controller
 * says so); the API keeps it longer and forgets it the moment staff change a
 * video.
 */
Route::get('/help/videos', HelpVideoController::class)->middleware('throttle:60,1');
