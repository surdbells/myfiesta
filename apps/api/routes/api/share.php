<?php

// Friend discounts: a buyer's link that saves a friend money and earns them a code.

use App\Http\Controllers\Api\Organizer\ShareOfferController;
use App\Http\Controllers\Api\ShareLinkController;
use Illuminate\Support\Facades\Route;

/*
 * Attendee: somebody signed in on the phone, holding a ticket to the night,
 * asking for their own link (ShareLinkController). A buyer is given theirs
 * when they pay, so this is for everybody else holding one.
 */
Route::middleware(['auth:sanctum', 'token.scope:attendee', 'throttle:20,1'])->group(function () {
    Route::post('/events/{slug}/share-link', [ShareLinkController::class, 'store']);
});

/*
 * Public: whether a ref is a friend's link that takes money off a night, for
 * the event page's greeting (site and phone). Read like the page itself
 * (routes/api.php's $publicRead): never kept past the moment, since a link
 * stops working when its holder is refunded.
 */
Route::get('/events/{slug}/friend-discount', [ShareLinkController::class, 'show'])
    ->middleware(['cache.headers:public;max_age=0;must_revalidate', 'throttle:60,1']);

/*
 * The organizer console, as in routes/api.php: authorised against the
 * organization owning the event (codes.manage), not the token alone.
 */
Route::middleware(['auth:sanctum', 'token.scope:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::put('/events/{event:id}/share-offer', [ShareOfferController::class, 'update'])->middleware('throttle:30,1');
    });
