<?php

// A profile photo and the time zone the phone greets somebody in.

use App\Http\Controllers\Api\AccountController;
use Illuminate\Support\Facades\Route;

/*
 * The account's own photo.
 *
 * On the account scope like the rest of the profile, so a door pass — which
 * Sanctum sees as the account that issued it — cannot put a face on somebody
 * else's account. The time zone is saved with the rest of the details,
 * through PATCH /auth/profile.
 */
Route::middleware(['auth:sanctum', 'token.scope:account'])->group(function () {
    // Each one decodes and re-encodes a photograph. Twenty a minute is more
    // than anybody choosing a picture of themselves needs.
    Route::post('/auth/avatar', [AccountController::class, 'storeAvatar'])->middleware('throttle:20,1');
    Route::delete('/auth/avatar', [AccountController::class, 'destroyAvatar']);
});
