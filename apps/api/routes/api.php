<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\DoorController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\OrderStatusController;
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
