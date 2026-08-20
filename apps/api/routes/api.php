<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\DoorController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\Organizer\CodeController;
use App\Http\Controllers\Api\Organizer\EventController as OrganizerEventController;
use App\Http\Controllers\Api\Organizer\EventImageController;
use App\Http\Controllers\Api\Organizer\GuestController;
use App\Http\Controllers\Api\Organizer\IssuedTicketController;
use App\Http\Controllers\Api\Organizer\RefundController;
use App\Http\Controllers\Api\Organizer\ReminderController;
use App\Http\Controllers\Api\Organizer\TicketTypeController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

/*
 * Public. No token, because browsing and buying require no account — guest
 * checkout is the primary path and the shareable link is the front door.
 *
 * Quoting is rate limited more loosely than ordering: a cart recalculates on
 * every change, while creating an order takes locks and reserves stock.
 */
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{slug}', [EventController::class, 'show']);
Route::get('/orders/{reference}', OrderStatusController::class);

Route::middleware('throttle:120,1')
    ->post('/events/{slug}/quote', [CheckoutController::class, 'quote']);

Route::middleware('throttle:20,1')
    ->post('/events/{slug}/orders', [CheckoutController::class, 'store']);

/*
 * Attendee. An account is optional for buying and required for these, because
 * they act on tickets somebody owns.
 */
Route::middleware(['auth:sanctum', 'token.scope:attendee'])->group(function () {
    Route::get('/me/tickets', [TicketController::class, 'index']);
    Route::post('/tickets/{ticket}/transfer', [TicketController::class, 'transfer']);
});

/*
 * The door.
 *
 * Accepts an organizer token — organizers often work their own door — or a
 * door token, which the middleware binds to a single event. Everything else in
 * the API refuses a door token outright.
 */
// Bound by id, not slug. Events resolve by slug everywhere else because that
// is what a shareable link carries, but a door token is scoped as
// door:{event_id} — so the id is what the scanner has and what the middleware
// compares against.
Route::middleware(['auth:sanctum', 'token.scope:door'])
    ->post('/events/{event:id}/scan', [DoorController::class, 'scan']);

/*
 * Signing in.
 *
 * Abilities are decided by the server from what the account is, never from
 * what the client asks for.
 */
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

/*
 * The organizer console.
 *
 * Every route authorises against the organization owning the record, not the
 * token alone — membership grants the ability, and the policy decides whether
 * this member may do this thing to this event.
 */
Route::middleware(['auth:sanctum', 'token.scope:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::get('/events', [OrganizerEventController::class, 'index']);
        Route::post('/events', [OrganizerEventController::class, 'store']);
        Route::get('/events/{event:id}', [OrganizerEventController::class, 'show']);
        Route::patch('/events/{event:id}', [OrganizerEventController::class, 'update']);
        Route::post('/events/{event:id}/publish', [OrganizerEventController::class, 'publish']);
        Route::get('/events/{event:id}/summary', [OrganizerEventController::class, 'summary']);

        Route::get('/events/{event:id}/ticket-types', [TicketTypeController::class, 'index']);
        Route::post('/events/{event:id}/ticket-types', [TicketTypeController::class, 'store']);
        Route::patch('/events/{event:id}/ticket-types/{ticketType:id}', [TicketTypeController::class, 'update']);
        Route::delete('/events/{event:id}/ticket-types/{ticketType:id}', [TicketTypeController::class, 'destroy']);

        Route::get('/events/{event:id}/guests', [GuestController::class, 'index']);
        Route::post('/events/{event:id}/tickets', [IssuedTicketController::class, 'store']);

        Route::get('/events/{event:id}/images', [EventImageController::class, 'index']);
        Route::post('/events/{event:id}/images', [EventImageController::class, 'store']);
        Route::patch('/events/{event:id}/images/{image:id}', [EventImageController::class, 'update']);
        Route::delete('/events/{event:id}/images/{image:id}', [EventImageController::class, 'destroy']);
        Route::post('/events/{event:id}/images/order', [EventImageController::class, 'reorder']);

        Route::get('/events/{event:id}/reminders', [ReminderController::class, 'index']);
        Route::post('/events/{event:id}/reminders', [ReminderController::class, 'store']);
        Route::delete('/events/{event:id}/reminders/{reminder:id}', [ReminderController::class, 'destroy']);

        Route::get('/events/{event:id}/orders', [RefundController::class, 'index']);
        Route::post('/events/{event:id}/orders/{order:id}/refunds', [RefundController::class, 'store']);

        Route::get('/events/{event:id}/codes', [CodeController::class, 'index']);
        Route::post('/events/{event:id}/codes', [CodeController::class, 'store']);
        // {code} rather than {code:id}: naming the key makes Laravel scope the
        // binding to the parent, and a code may be organization-wide with no
        // event at all — scoping would make those unreachable. Ownership is
        // checked in the controller instead.
        Route::delete('/events/{event:id}/codes/{code}', [CodeController::class, 'destroy']);
    });
