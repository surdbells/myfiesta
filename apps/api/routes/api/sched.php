<?php

// Going on sale at a set time, and repeating nights that put themselves on sale.
//
// The time a night goes on sale is a field of PATCH /organizer/events/{event}
// (Organizer\EventController::update, which belongs to this feature), so it
// has no route here.

use App\Http\Controllers\Api\Organizer\SeriesController;
use Illuminate\Support\Facades\Route;

/*
 * The organizer console, as in routes/api.php: every route authorises against
 * the organization owning the record (SeriesController), not the token alone.
 */
Route::middleware(['auth:sanctum', 'token.scope:organizer'])
    ->prefix('organizer')
    ->group(function () {
        // How a series ends, and whether its dates put themselves on sale.
        Route::patch('/events/{event:id}/series', [SeriesController::class, 'update']);
    });
