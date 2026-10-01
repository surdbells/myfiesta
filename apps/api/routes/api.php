<?php

use App\Http\Controllers\Api\AccessCodeController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DataRequestController;
use App\Http\Controllers\Api\DiscoverController;
use App\Http\Controllers\Api\DoorController;
use App\Http\Controllers\Api\DoorPassController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\EventCategoryController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventViewController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\Organizer\AddOnController;
use App\Http\Controllers\Api\Organizer\BrandController;
use App\Http\Controllers\Api\Organizer\CampaignController;
use App\Http\Controllers\Api\Organizer\CodeBatchController;
use App\Http\Controllers\Api\Organizer\CodeController;
use App\Http\Controllers\Api\Organizer\DoorPassController as OrganizerDoorPassController;
use App\Http\Controllers\Api\Organizer\EventController as OrganizerEventController;
use App\Http\Controllers\Api\Organizer\EventImageController;
use App\Http\Controllers\Api\Organizer\GuestController;
use App\Http\Controllers\Api\Organizer\IntegrationController;
use App\Http\Controllers\Api\Organizer\IssuedTicketController;
use App\Http\Controllers\Api\Organizer\MessageController;
use App\Http\Controllers\Api\Organizer\OrderController as OrganizerOrderController;
use App\Http\Controllers\Api\Organizer\OverviewController;
use App\Http\Controllers\Api\Organizer\PayoutController;
use App\Http\Controllers\Api\Organizer\QuestionController;
use App\Http\Controllers\Api\Organizer\RefundController;
use App\Http\Controllers\Api\Organizer\ReminderController;
use App\Http\Controllers\Api\Organizer\SavedViewController;
use App\Http\Controllers\Api\Organizer\SeriesController;
use App\Http\Controllers\Api\Organizer\StandingController;
use App\Http\Controllers\Api\Organizer\TeamController;
use App\Http\Controllers\Api\Organizer\TicketTypeController;
use App\Http\Controllers\Api\Organizer\WaitlistController as OrganizerWaitlistController;
use App\Http\Controllers\Api\OrganizerController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\ResaleController;
use App\Http\Controllers\Api\SavedEventController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\TermsController;
use App\Http\Controllers\Api\TicketAccessController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\V1\ReadController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Middleware\ThrottleHealthChecks;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

// Whether the database, cache, queue, disks and scheduler all work: for an
// uptime monitor, never a load balancer (docs/OPERATIONS.md). Limited, because
// every call writes a file and reads the database — by a limiter that gives
// way when Redis, which it counts in, is what is down.
Route::get('/health/ready', ReadinessController::class)->middleware(ThrottleHealthChecks::class.':30,1');

/*
 * Public. No token, because browsing and buying require no account — guest
 * checkout is the primary path and the shareable link is the front door.
 *
 * Quoting is rate limited more loosely than ordering: a cart recalculates on
 * every change, while creating an order takes locks and reserves stock.
 */

/*
 * The public reads that pages are rendered from: the same answer for
 * everybody, whoever asks.
 *
 * Said so ("public"), and never kept past the moment (max-age=0 and
 * must-revalidate), so nothing serves one stale and the event page's badges
 * still agree with checkout. Laravel's default for a response that says
 * nothing is "no-cache, private", and the public site's server render will
 * not hand a private answer on to the browser — Angular's transfer cache
 * refuses one — so every page it rendered fetched all of it again on
 * arrival, and a city's page flashed back to its skeletons while it did.
 */
$publicRead = 'cache.headers:public;max_age=0;must_revalidate';

Route::get('/discover', DiscoverController::class)->middleware($publicRead);
// The listing's filters, and the pages each category and city has of its own.
Route::get('/discover/facets', [DiscoverController::class, 'facets'])->middleware($publicRead);
Route::get('/discover/categories/{slug}', [DiscoverController::class, 'category'])->middleware($publicRead);
Route::get('/discover/cities/{slug}', [DiscoverController::class, 'city'])->middleware($publicRead);

// Proxied by the public site as its own /sitemap.xml.
Route::get('/sitemap.xml', SitemapController::class)->middleware('throttle:30,1');

// The fixed list the console's category dropdown offers and the API accepts.
Route::get('/event-categories', EventCategoryController::class);

// Who operates the platform, for the site's contact and legal pages.
Route::get('/contact', ContactController::class);
Route::get('/events', [EventController::class, 'index'])->middleware($publicRead);
Route::get('/events/{slug}', [EventController::class, 'show'])->middleware($publicRead);
Route::get('/events/{slug}/calendar.ics', [EventController::class, 'calendar'])->middleware('throttle:60,1');

/*
 * "Send me what you hold about me", and "forget me".
 *
 * No account needed: most people holding a ticket here have never had one.
 * Throttled hard — each one sends an email to an address somebody typed.
 */
Route::post('/privacy/requests', [DataRequestController::class, 'store'])->middleware('throttle:5,60');

// A page view, counted and nothing else. See EventViewController.
Route::post('/events/{slug}/views', EventViewController::class)->middleware('throttle:30,1');
Route::get('/orders/{reference}', OrderStatusController::class);

/*
 * An organizer's own page: who they are, what is on, what has been.
 *
 * Public, because it is the link a promoter puts in a bio — and the page that
 * makes following somebody lead somewhere.
 */
Route::get('/organizers/{slug}', [OrganizerController::class, 'show'])->middleware($publicRead);

/*
 * A buyer's tickets, with the QR the door reads.
 *
 * Unauthenticated on purpose: guest checkout is the primary path, so most
 * people holding a ticket have no account, and the random token in the link is
 * the whole credential.
 */
Route::get('/tickets/{token}', TicketAccessController::class)->middleware('throttle:60,1');

// The night as a calendar file, from the ticket page, on the same credential:
// so that adding it to a diary is in the order's ticket history.
Route::get('/tickets/{token}/calendar.ics', [TicketAccessController::class, 'calendar'])->middleware('throttle:60,1');

// Giving one back, on the same credential as the tickets themselves.
Route::post('/tickets/{token}/resale/{ticket}', [ResaleController::class, 'store'])->middleware('throttle:20,1');
Route::delete('/tickets/{token}/resale/{ticket}', [ResaleController::class, 'destroy'])->middleware('throttle:20,1');

Route::middleware('throttle:120,1')
    ->post('/events/{slug}/quote', [CheckoutController::class, 'quote']);

// Joining the waitlist for a sold-out event.
Route::middleware('throttle:10,1')
    ->post('/events/{slug}/waitlist', WaitlistController::class);

// Ten tries a minute: an answer confirms a presale code exists.
Route::middleware('throttle:10,1')
    ->post('/events/{slug}/access', AccessCodeController::class);

Route::middleware('throttle:20,1')
    ->post('/events/{slug}/orders', [CheckoutController::class, 'store']);

/*
 * Attendee. An account is optional for buying and required for these, because
 * they act on tickets somebody owns.
 */
Route::middleware(['auth:sanctum', 'token.scope:attendee'])->group(function () {
    Route::get('/me/tickets', [TicketController::class, 'index']);
    Route::post('/tickets/{ticket}/transfer', [TicketController::class, 'transfer']);

    // Nights to come back to, and organizers to hear from. Both are private
    // lists: an organizer is told how many follow them, never who.
    Route::get('/me/saved', [SavedEventController::class, 'index']);
    Route::put('/events/{slug}/save', [SavedEventController::class, 'store']);
    Route::delete('/events/{slug}/save', [SavedEventController::class, 'destroy']);

    Route::get('/me/following', [FollowController::class, 'index']);
    Route::put('/organizers/{slug}/follow', [FollowController::class, 'store']);
    Route::delete('/organizers/{slug}/follow', [FollowController::class, 'destroy']);
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

    /*
     * Selling to somebody standing at the door.
     *
     * On the door token deliberately: the person selling walk-ups is the
     * person on the door. The order names who took it and on which phone,
     * which is the control that matters for money handled in a doorway.
     */
    Route::get('/events/{event:id}/sellable', [DoorController::class, 'sellable']);
    Route::post('/events/{event:id}/door-quote', [DoorController::class, 'quote']);
    Route::post('/events/{event:id}/door-sales', [DoorController::class, 'sell']);
    Route::get('/events/{event:id}/takings', [DoorController::class, 'takings']);
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

// An invitation to join an organization: what it offers, and accepting it as
// the signed-in user. Throttled — a token lookup is a guess otherwise.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:20,1');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware(['auth:sanctum', 'token.scope:account', 'throttle:20,1']);

// A door link, opened on the phone that will scan with it.
Route::get('/door-passes/{secret}', [DoorPassController::class, 'show'])->middleware('throttle:20,1');
Route::post('/door-passes/{secret}/claim', [DoorPassController::class, 'claim'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [AccountController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/auth/reset-password', [AccountController::class, 'resetPassword'])->middleware('throttle:10,1');

// The link sent to a new email address. Like a reset link, it is the
// credential, and it is usually opened somewhere nobody is signed in.
Route::post('/auth/email/confirm', [AccountController::class, 'confirmEmailChange'])->middleware('throttle:10,1');

// Signing out is open to every token, a door pass included: ending a shift
// should throw the pass away rather than leave it live on the phone.
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'token.scope:account'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/profile', [AccountController::class, 'updateProfile']);
    Route::post('/auth/password', [AccountController::class, 'changePassword']);
    // Asks, never changes: the move happens when the new address opens its link.
    Route::post('/auth/email', [AccountController::class, 'requestEmailChange'])->middleware('throttle:10,1');
    // A fresh link to prove the address the account already has. Limited per
    // account inside as well; this only keeps a stuck button from hammering.
    Route::post('/auth/email/verification', EmailVerificationController::class)->middleware('throttle:6,1');

    // Deleting the account from inside the app that made it, which the App
    // Store requires. The privacy page's erasure, asked for signed in: what it
    // would do first, then doing it with the account's password.
    Route::get('/auth/erasure', [DataRequestController::class, 'preview']);
    Route::post('/auth/erasure', [DataRequestController::class, 'erase'])->middleware('throttle:10,1');

    // The terms, the privacy policy and the refund policy: whether this
    // account agreed to the words in force now, and agreeing. For accounts
    // that were never asked — made before sign-up asked, or brought over —
    // and for new words.
    Route::get('/auth/terms', [TermsController::class, 'show']);
    Route::post('/auth/terms', [TermsController::class, 'accept'])->middleware('throttle:10,1');
});

/*
 * myFiesta staff acting as an organization.
 *
 * Started in the admin panel, never here. The console trades the one-minute
 * code from the link for the session's token, reads the session for its
 * banner, and ends it. What the token may not do is refused by
 * ImpersonationBoundary on the api group and by the permissions it withholds.
 */
Route::post('/impersonation/exchange', [ImpersonationController::class, 'exchange'])->middleware('throttle:10,1');
Route::middleware(['auth:sanctum', 'token.scope:impersonation'])->group(function () {
    Route::get('/impersonation', [ImpersonationController::class, 'show']);
    Route::delete('/impersonation', [ImpersonationController::class, 'destroy']);
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

        // Whether the platform is selling for this organization: the
        // console's suspension banner, open to every member.
        Route::get('/standing', [StandingController::class, 'show']);

        // What is owed, what has been sent, and where it goes. Read-only
        // for settlements: paying somebody out is a manual act against a
        // real bank, and an endpoint for it would let an organizer mark
        // themselves as paid.
        Route::get('/payouts', [PayoutController::class, 'index']);

        // Every order the organization has taken, across its events —
        // searchable by reference, name or address, which is how support
        // arrives rather than knowing which night it was.
        Route::get('/orders', [OrganizerOrderController::class, 'index']);
        Route::get('/orders/export', [OrganizerOrderController::class, 'export']);

        // The views somebody keeps of a list: its filters, sort and columns,
        // under a name. Theirs alone, for this organization.
        Route::get('/saved-views', [SavedViewController::class, 'index']);
        Route::post('/saved-views', [SavedViewController::class, 'store'])->middleware('throttle:60,1');
        Route::delete('/saved-views/{view}', [SavedViewController::class, 'destroy'])->whereUuid('view');

        // Where the money goes, and asking for it, wait for a proved address
        // (verified.email): an account made under somebody else's address
        // must not be able to point an organization's money anywhere.
        Route::put('/payout-details', [PayoutController::class, 'update'])->middleware('verified.email');

        // Asking to be paid. Staff pay it, or say why not, in the admin panel.
        Route::post('/payouts/requests', [PayoutController::class, 'requestPayout'])->middleware('verified.email');
        Route::delete('/payouts/requests/{payoutRequest}', [PayoutController::class, 'cancelRequest']);

        /*
         * How the organization appears on the pages it sells from. Any staff
         * member may read it; the owner may change it.
         */
        Route::get('/brand', [BrandController::class, 'show']);
        Route::patch('/brand', [BrandController::class, 'update']);
        Route::post('/brand/logo', [BrandController::class, 'storeLogo'])->middleware('throttle:60,1');
        Route::delete('/brand/logo', [BrandController::class, 'destroyLogo']);

        /*
         * Campaigns: writing to people who might come. Anybody who can
         * message ticket holders; who they may reach is decided inside.
         */
        Route::get('/campaigns', [CampaignController::class, 'index']);
        Route::post('/campaigns/audience', [CampaignController::class, 'audience'])->middleware('throttle:60,1');
        Route::post('/campaigns', [CampaignController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('throttle:20,1');
        Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);

        /*
         * Other systems: webhooks out, keys in. Owner-only, checked inside —
         * the list names where the organization's data goes.
         */
        Route::get('/integrations', [IntegrationController::class, 'index']);
        Route::post('/integrations/webhooks', [IntegrationController::class, 'storeEndpoint'])->middleware('throttle:20,1');
        Route::patch('/integrations/webhooks/{endpoint}', [IntegrationController::class, 'updateEndpoint']);
        Route::delete('/integrations/webhooks/{endpoint}', [IntegrationController::class, 'destroyEndpoint']);
        Route::post('/integrations/webhooks/{endpoint}/test', [IntegrationController::class, 'test'])->middleware('throttle:10,1');
        Route::get('/integrations/webhooks/{endpoint}/deliveries', [IntegrationController::class, 'deliveries']);
        Route::post('/integrations/keys', [IntegrationController::class, 'storeKey'])->middleware('throttle:20,1');
        Route::delete('/integrations/keys/{key}', [IntegrationController::class, 'revokeKey']);

        // The team: who is on it, in what role, and invitations to it.
        Route::get('/team', [TeamController::class, 'index']);
        Route::post('/team/invitations', [TeamController::class, 'invite']);
        Route::delete('/team/invitations/{invitation}', [TeamController::class, 'revoke']);
        Route::patch('/team/members/{member}', [TeamController::class, 'updateRole']);
        Route::delete('/team/members/{member}', [TeamController::class, 'remove']);

        Route::get('/events', [OrganizerEventController::class, 'index']);
        // Before {event:id}, which would otherwise take 'options' for an id.
        Route::get('/events/options', [OrganizerEventController::class, 'options']);
        Route::post('/events', [OrganizerEventController::class, 'store']);
        Route::get('/events/{event:id}', [OrganizerEventController::class, 'show']);
        Route::patch('/events/{event:id}', [OrganizerEventController::class, 'update']);
        Route::get('/events/{event:id}/series', [SeriesController::class, 'show']);
        Route::post('/events/{event:id}/series', [SeriesController::class, 'store']);
        Route::post('/events/{event:id}/series/skip', [SeriesController::class, 'skip']);
        Route::delete('/events/{event:id}/series', [SeriesController::class, 'destroy']);

        // Duplicating is in routes/api/clone.php (EventCopyController).
        Route::get('/events/{event:id}/cancellation', [OrganizerEventController::class, 'cancellationPreview']);
        Route::post('/events/{event:id}/cancel', [OrganizerEventController::class, 'cancel']);
        // Putting a night on sale under a name waits for a proved address
        // (verified.email). Taking it off sale, drafts, edits and everything
        // else do not.
        Route::post('/events/{event:id}/publish', [OrganizerEventController::class, 'publish'])->middleware('verified.email:published');
        // Sending a night to myFiesta to be looked at before it goes on sale
        // (EventReviews), and taking it back to change something.
        Route::post('/events/{event:id}/submit', [OrganizerEventController::class, 'submit'])->middleware('verified.email');
        Route::post('/events/{event:id}/withdraw', [OrganizerEventController::class, 'withdraw']);
        Route::get('/events/{event:id}/summary', [OrganizerEventController::class, 'summary']);
        Route::get('/events/{event:id}/sales', [OrganizerEventController::class, 'sales']);

        Route::get('/events/{event:id}/ticket-types', [TicketTypeController::class, 'index']);
        Route::post('/events/{event:id}/ticket-types', [TicketTypeController::class, 'store']);
        Route::patch('/events/{event:id}/ticket-types/{ticketType:id}', [TicketTypeController::class, 'update']);
        Route::delete('/events/{event:id}/ticket-types/{ticketType:id}', [TicketTypeController::class, 'destroy']);
        // The order tiers are offered in, as one list — see the controller for
        // why this is not a sort_order per tier.
        Route::post('/events/{event:id}/ticket-types/order', [TicketTypeController::class, 'reorder']);

        // What is sold beside a ticket: a table, a bottle, a shirt.
        Route::get('/events/{event:id}/add-ons', [AddOnController::class, 'index']);
        Route::post('/events/{event:id}/add-ons', [AddOnController::class, 'store']);
        Route::patch('/events/{event:id}/add-ons/{addOn:id}', [AddOnController::class, 'update']);
        Route::delete('/events/{event:id}/add-ons/{addOn:id}', [AddOnController::class, 'destroy']);
        Route::post('/events/{event:id}/add-ons/order', [AddOnController::class, 'reorder']);

        // What the checkout asks, and the order it asks in.
        Route::get('/events/{event:id}/questions', [QuestionController::class, 'index']);
        Route::post('/events/{event:id}/questions', [QuestionController::class, 'store']);
        Route::patch('/events/{event:id}/questions/{question:id}', [QuestionController::class, 'update']);
        Route::delete('/events/{event:id}/questions/{question:id}', [QuestionController::class, 'destroy']);
        Route::post('/events/{event:id}/questions/order', [QuestionController::class, 'reorder']);

        Route::get('/events/{event:id}/guests', [GuestController::class, 'index']);
        Route::get('/events/{event:id}/guests/export', [GuestController::class, 'export']);
        Route::post('/events/{event:id}/tickets', [IssuedTicketController::class, 'store']);

        Route::get('/events/{event:id}/images', [EventImageController::class, 'index']);
        /*
         * Decoding an image is the one expensive thing behind this door: a
         * 12MB upload becomes several renditions in memory. Sixty a minute
         * comfortably covers dropping a whole gallery in at once — the cap is
         * thirty per event — and still puts a ceiling on the cost.
         */
        Route::post('/events/{event:id}/images', [EventImageController::class, 'store'])
            ->middleware('throttle:60,1');
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

        // Every code across the organization's events, for the Discount codes screen.
        Route::get('/codes', [CodeController::class, 'all']);
        // Several at once, off or back on: the codes list's bulk action.
        Route::post('/codes/active', [CodeController::class, 'setActive'])->middleware('throttle:30,1');

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

        Route::get('/events/{event:id}/door-passes', [OrganizerDoorPassController::class, 'index']);
        Route::post('/events/{event:id}/door-passes', [OrganizerDoorPassController::class, 'store']);
        Route::delete('/events/{event:id}/door-passes/{pass}', [OrganizerDoorPassController::class, 'destroy']);

        Route::get('/events/{event:id}/waitlist', [OrganizerWaitlistController::class, 'index']);
        Route::post('/events/{event:id}/waitlist/notify', [OrganizerWaitlistController::class, 'notify']);

        // Batches of single-use codes, for handing out one per person.
        Route::get('/events/{event:id}/code-batches', [CodeBatchController::class, 'index']);
        Route::post('/events/{event:id}/code-batches', [CodeBatchController::class, 'store']);
        Route::get('/events/{event:id}/code-batches/{batch}/export', [CodeBatchController::class, 'export']);
        Route::post('/events/{event:id}/code-batches/{batch}/deactivate', [CodeBatchController::class, 'deactivate']);
    });

/*
 * Another system reading an organization's data with one of its keys.
 *
 * Versioned, because somebody else's code depends on these shapes and cannot
 * be redeployed alongside ours. Read only; the key decides the organization.
 * Throttled before the key is checked, so guessing keys costs the same budget.
 */
Route::prefix('v1')->middleware(['throttle:api-key', 'api.key'])->group(function () {
    Route::get('/events', [ReadController::class, 'events']);
    Route::get('/events/{event}/orders', [ReadController::class, 'orders']);
    Route::get('/events/{event}/attendees', [ReadController::class, 'attendees']);
});

/*
 * One file for each feature added since, in a fixed order.
 *
 * Each is a whole route file of its own: it declares its own middleware
 * groups (throttles, auth:sanctum with token.scope:attendee, account or
 * organizer, the organizer prefix, the public-read cache header) rather than
 * reaching into the groups or variables above, so a feature is added, read
 * and reviewed in one place and two features never edit the same lines.
 * Required here, inside the api group, so each is under /api with the api
 * middleware like everything above. The order only decides which of two
 * clashing paths wins, and none should clash.
 */
require __DIR__.'/api/pay.php';
require __DIR__.'/api/transfer.php';
require __DIR__.'/api/profile.php';
require __DIR__.'/api/public.php';
require __DIR__.'/api/sched.php';
require __DIR__.'/api/clone.php';
require __DIR__.'/api/share.php';
require __DIR__.'/api/survey.php';
require __DIR__.'/api/points.php';
require __DIR__.'/api/pass.php';
require __DIR__.'/api/wait.php';
require __DIR__.'/api/audience.php';
