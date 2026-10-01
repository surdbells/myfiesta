<?php

// Duplicating a night with changes, and templates to start one from.

use App\Http\Controllers\Api\Organizer\EventCopyController;
use Illuminate\Support\Facades\Route;

/*
 * The organizer console, as in routes/api.php: every route authorises against
 * the organization owning the record (EventCopyController), not the token
 * alone.
 */
Route::middleware(['auth:sanctum', 'token.scope:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::post('/events/{event:id}/duplicate', [EventCopyController::class, 'duplicate']);
    });
