<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\DiscoverController;
use App\Http\Controllers\Api\DoorController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\Organizer\CodeController;
use App\Http\Controllers\Api\Organizer\EventController as OrganizerEventController;
use App\Http\Controllers\Api\Organizer\EventImageController;
use App\Http\Controllers\Api\Organizer\OrderController as OrganizerOrderController;
use App\Http\Controllers\Api\Organizer\OverviewController;
use App\Http\Controllers\Api\Organizer\PayoutController;
use App\Http\Controllers\Api\Organizer\GuestController;
use App\Http\Controllers\Api\Organizer\IssuedTicketController;
use App\Http\Controllers\Api\Organizer\MessageController;
use App\Http\Controllers\Api\Organizer\RefundController;
use App\Http\Controllers\Api\Organizer\ReminderController;
use App\Http\Controllers\Api\Organizer\SeriesController;
use App\Http\Controllers\Api\Organizer\TicketTypeController;
use App\Http\Controllers\Api\TicketAccessController;
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
Route::get('/discover', DiscoverController::class);
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{slug}', [EventController::class, 'show']);
Route::get('/orders/{reference}', OrderStatusController::class);

/*
 * A buyer's tickets, with the QR the door reads.
 *
 * Unauthenticated on purpose: guest checkout is the primary path, so most
 * people holding a ticket have no account, and the random token in the link is
 * the whole credential.
 */
Route::get('/tickets/{token}', TicketAccessController::class)->middleware('throttle:60,1');

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
Route::middleware(['auth:sanctum', 'token.scope:door'])->group(function () {
    Route::post('/events/{event:id}/scan', [DoorController::class, 'scan']);

    // Offline scanning: the list a phone decides from when the signal goes,
    // and the scans it made meanwhile, sent back once it returns.
    Route::get('/events/{event:id}/door-list', [DoorController::class, 'list']);
    Route::post('/events/{event:id}/scans/sync', [DoorController::class, 'sync']);
});

/*
 * Signing in.
 *
 * Abilities are decided by the server from what the account is, never from
 * what the client asks for.
 */
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

/*
 * Getting an account, and getting back into one.
 *
 * Unauthenticated by necessity — everything here is what somebody does when
 * they have no token. Each is rate limited inside the controller on the pair
 * that actually identifies the attempt, not just on the route.
 */
Route::post('/auth/register', [AccountController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [AccountController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/auth/reset-password', [AccountController::class, 'resetPassword'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/profile', [AccountController::class, 'updateProfile']);
    Route::post('/auth/password', [AccountController::class, 'changePassword']);
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
        // What somebody signs in to find out, in one request.
        Route::get('/overview', [OverviewController::class, 'index']);

        // What is owed, what has been sent, and where it goes. Read-only
        // for settlements: paying somebody out is a manual act against a
        // real bank, and an endpoint for it would let an organizer mark
        // themselves as paid.
        Route::get('/payouts', [PayoutController::class, 'index']);

        // Every order the organization has taken, across its events —
        // searchable by reference, name or address, which is how support
        // arrives rather than knowing which night it was.
        Route::get('/orders', [OrganizerOrderController::class, 'index']);
        Route::put('/payout-details', [PayoutController::class, 'update']);

        Route::get('/events', [OrganizerEventController::class, 'index']);
        Route::post('/events', [OrganizerEventController::class, 'store']);
        Route::get('/events/{event:id}', [OrganizerEventController::class, 'show']);
        Route::patch('/events/{event:id}', [OrganizerEventController::class, 'update']);
        Route::get('/events/{event:id}/series', [SeriesController::class, 'show']);
        Route::post('/events/{event:id}/series', [SeriesController::class, 'store']);
        Route::post('/events/{event:id}/series/skip', [SeriesController::class, 'skip']);
        Route::delete('/events/{event:id}/series', [SeriesController::class, 'destroy']);

        Route::post('/events/{event:id}/duplicate', [OrganizerEventController::class, 'duplicate']);
        Route::get('/events/{event:id}/cancellation', [OrganizerEventController::class, 'cancellationPreview']);
        Route::post('/events/{event:id}/cancel', [OrganizerEventController::class, 'cancel']);
        Route::post('/events/{event:id}/publish', [OrganizerEventController::class, 'publish']);
        Route::get('/events/{event:id}/summary', [OrganizerEventController::class, 'summary']);

        Route::get('/events/{event:id}/ticket-types', [TicketTypeController::class, 'index']);
        Route::post('/events/{event:id}/ticket-types', [TicketTypeController::class, 'store']);
        Route::patch('/events/{event:id}/ticket-types/{ticketType:id}', [TicketTypeController::class, 'update']);
        Route::delete('/events/{event:id}/ticket-types/{ticketType:id}', [TicketTypeController::class, 'destroy']);
        // The order tiers are offered in, as one list — see the controller for
        // why this is not a sort_order per tier.
        Route::post('/events/{event:id}/ticket-types/order', [TicketTypeController::class, 'reorder']);

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

        Route::get('/events/{event:id}/messages', [MessageController::class, 'index']);
        Route::post('/events/{event:id}/messages', [MessageController::class, 'store']);

        Route::get('/events/{event:id}/codes', [CodeController::class, 'index']);
        Route::post('/events/{event:id}/codes', [CodeController::class, 'store']);
        // {code} rather than {code:id}: naming the key makes Laravel scope the
        // binding to the parent, and a code may be organization-wide with no
        // event at all — scoping would make those unreachable. Ownership is
        // checked in the controller instead.
        // Editable rather than delete-and-recreate: recreating a code throws
        // away its redemption count and the promoter attribution with it.
        Route::patch('/events/{event:id}/codes/{code}', [CodeController::class, 'update']);
        Route::delete('/events/{event:id}/codes/{code}', [CodeController::class, 'destroy']);
    });
