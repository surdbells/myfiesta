<?php

// Duplicating a night with changes, and templates to start one from.

use App\Http\Controllers\Api\Organizer\EventCopyController;
use App\Http\Controllers\Api\Organizer\EventTemplateController;
use Illuminate\Support\Facades\Route;

/*
 * The organizer console, as in routes/api.php: every route authorises against
 * the organization owning the record (EventCopyController,
 * EventTemplateController), not the token alone.
 */
Route::middleware(['auth:sanctum', 'token.scope:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::post('/events/{event:id}/duplicate', [EventCopyController::class, 'duplicate']);

        // An event kept as a starting point, and new events made from one.
        Route::get('/templates', [EventTemplateController::class, 'index']);
        Route::post('/templates', [EventTemplateController::class, 'store']);
        Route::delete('/templates/{template}', [EventTemplateController::class, 'destroy'])->whereUuid('template');
        Route::post('/templates/{template}/events', [EventTemplateController::class, 'createEvent'])->whereUuid('template');
    });
