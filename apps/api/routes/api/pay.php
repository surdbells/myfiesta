<?php

// Paying later (Klarna and Affirm through Stripe) and payments that settle after checkout.
//
// The organizer's opt-in for a night is a route of its own here
// (PUT /organizer/events/{event}/pay-later), not a field of
// Organizer\EventController::update, which belongs to scheduling.

use App\Http\Controllers\Api\Organizer\PayLaterController;
use Illuminate\Support\Facades\Route;

/*
 * The organizer console, as in routes/api.php: authorised against the
 * organization owning the event (PayLaterController), not the token alone.
 */
Route::middleware(['auth:sanctum', 'token.scope:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::get('/events/{event:id}/pay-later', [PayLaterController::class, 'show']);
        Route::put('/events/{event:id}/pay-later', [PayLaterController::class, 'update'])->middleware('throttle:30,1');
    });
