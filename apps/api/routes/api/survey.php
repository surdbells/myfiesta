<?php

// Asking the people who came what they thought, and what they said.

use App\Http\Controllers\Api\Organizer\SurveyController as OrganizerSurveyController;
use App\Http\Controllers\Api\Organizer\SurveyTemplateController;
use App\Http\Controllers\Api\SurveyController;
use Illuminate\Support\Facades\Route;

/*
 * The survey from the email's link. No sign-in: the token is the whole
 * credential, as on the ticket link. Throttled per address, the answer more
 * tightly: one answer per link, and a token is not something to guess at.
 */
Route::get('/surveys/{token}', [SurveyController::class, 'show'])->middleware('throttle:60,1');
Route::post('/surveys/{token}', [SurveyController::class, 'answer'])->middleware('throttle:10,1');

/*
 * The organizer console, as in routes/api.php: the organization comes from
 * X-Organization, checked against membership, and a night's survey is
 * authorised against the organization that owns the night.
 */
Route::middleware(['auth:sanctum', 'token.scope:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::get('/surveys', [OrganizerSurveyController::class, 'overview']);
        Route::put('/surveys/settings', [OrganizerSurveyController::class, 'settings']);

        Route::get('/survey-templates', [SurveyTemplateController::class, 'index']);
        Route::post('/survey-templates', [SurveyTemplateController::class, 'store'])->middleware('throttle:30,1');
        Route::put('/survey-templates/{template}', [SurveyTemplateController::class, 'update'])->whereUuid('template');
        Route::delete('/survey-templates/{template}', [SurveyTemplateController::class, 'destroy'])->whereUuid('template');

        Route::get('/events/{event:id}/survey', [OrganizerSurveyController::class, 'show']);
        Route::put('/events/{event:id}/survey', [OrganizerSurveyController::class, 'update']);
        Route::get('/events/{event:id}/survey/results', [OrganizerSurveyController::class, 'results']);
        Route::post('/events/{event:id}/survey/send', [OrganizerSurveyController::class, 'send'])->middleware('throttle:10,1');
    });
