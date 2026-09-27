<?php

use App\Http\Controllers\DataRequestPageController;
use App\Http\Controllers\DisputeFileController;
use App\Http\Controllers\EmailVerificationPageController;
use App\Http\Controllers\FollowLeaveController;
use App\Http\Controllers\IdentityDocumentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SignUpPageController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WaitlistLeaveController;
use App\Http\Controllers\Webhooks\InboundSmsController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

/*
 * Identity document images.
 *
 * Private disk, signed URL, five-minute expiry, and a role check inside the
 * controller as well — a leaked link must not become a permanent handle on
 * someone government ID.
 */
Route::get('/identity-documents/{document}', [IdentityDocumentController::class, 'show'])
    ->middleware(['auth', 'signed'])
    ->name('identity-documents.show');

/*
 * A dispute's documents — the receipt, delivery and entry, the refund policy
 * as shown, the emails — for Admin and Finance to preview or download before
 * sending. The same guard as identity documents: signed, minutes long, and a
 * role check inside.
 */
Route::get('/disputes/{dispute}/documents/{file}', DisputeFileController::class)
    ->middleware(['auth', 'signed'])
    ->whereIn('file', ['receipt', 'service_documentation', 'refund_policy', 'customer_communication', 'evidence_pack'])
    ->name('disputes.document');

/*
 * Payment webhooks.
 *
 * Deliberately outside any auth middleware — the gateway is not a logged-in
 * user — and outside CSRF, since there is no browser session to protect. The
 * signature is the authentication, checked inside each adapter against the raw
 * request body.
 */
Route::post('/webhooks/payments/{gateway}', PaymentWebhookController::class)
    ->name('webhooks.payments');

/*
 * A guest's tickets.
 *
 * Guest checkout is the primary path, so most buyers have no account to log
 * into. The signed link emailed to them is how they get back — expiring, and
 * tied to one order. The previous platform served this at /tickets/{sale_id}
 * with no signature at all, so anyone could read anyone else's by counting.
 */
Route::get('/orders/{order}', OrderController::class)
    ->middleware('signed')
    ->name('orders.show');

/*
 * Unsubscribing from reminders.
 *
 * No auth, because guest checkout means most recipients have no account and a
 * way out behind a login is not a way out. The token in the URL is the whole
 * credential — it identifies nothing on its own, and the worst it can do in the
 * wrong hands is stop emails the owner can turn back on.
 *
 * Outside CSRF for the same reason the webhooks are: Gmail and Outlook post to
 * this directly when they render their own unsubscribe button, and those
 * requests carry no session and no token.
 */
/*
 * Leaving a waitlist from its email: a page with a button, never a GET that
 * acts, because mail scanners follow links.
 */
Route::get('/waitlist/{token}/leave', [WaitlistLeaveController::class, 'show'])
    ->name('waitlist.leave');

Route::post('/waitlist/{token}/leave', [WaitlistLeaveController::class, 'store'])
    ->name('waitlist.leave.confirm');

/*
 * Leaving a following from its announcement: the same shape, and for the same
 * reason — a mail scanner must not be able to unfollow somebody.
 */
Route::get('/follows/{token}/leave', [FollowLeaveController::class, 'show'])
    ->name('follows.leave');

Route::post('/follows/{token}/leave', [FollowLeaveController::class, 'store'])
    ->name('follows.leave.confirm');

Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
    ->name('unsubscribe');

Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
    ->name('unsubscribe.confirm');

Route::post('/unsubscribe/{token}/resubscribe', [UnsubscribeController::class, 'resubscribe'])
    ->name('unsubscribe.resubscribe');

/*
 * A privacy request, from the link emailed to the address it is about.
 *
 * Here rather than on the site app, and plain HTML, because it has to work
 * with no account, no session and no JavaScript — often in a mail client's
 * own browser. The GET only shows what will happen; the POST is what acts.
 */
Route::get('/privacy/requests/{token}', [DataRequestPageController::class, 'show'])
    ->name('privacy.request')
    ->middleware('throttle:60,1');

Route::post('/privacy/requests/{token}', [DataRequestPageController::class, 'confirm'])
    ->name('privacy.confirm')
    ->middleware('throttle:20,1');

Route::get('/privacy/requests/{token}/download', [DataRequestPageController::class, 'download'])
    ->name('privacy.download')
    ->middleware('throttle:60,1');

/*
 * The link that finishes a sign-up, and the one that proves an existing
 * account's address.
 *
 * Plain pages here for the reason the privacy pages are: no account, no
 * session, often a mail client's own browser — and people signing up from the
 * phone app have no console to land on. Signed, relative to this host, and
 * checked inside each controller so a dead link gets a page that says so
 * rather than a bare 403. The GET only shows what will happen; the POST acts.
 */
Route::get('/sign-up/{registration}', [SignUpPageController::class, 'show'])
    ->name('sign-up.show')
    ->middleware('throttle:60,1');

Route::post('/sign-up/{registration}', [SignUpPageController::class, 'confirm'])
    ->name('sign-up.confirm')
    ->middleware('throttle:20,1');

Route::get('/verify-email/{user}/{hash}', [EmailVerificationPageController::class, 'show'])
    ->name('email.verify.show')
    ->middleware('throttle:60,1');

Route::post('/verify-email/{user}/{hash}', [EmailVerificationPageController::class, 'confirm'])
    ->name('email.verify.confirm')
    ->middleware('throttle:20,1');

/*
 * A text coming back the other way, which in practice means STOP.
 *
 * The secret is in the path because the providers sign inbound callbacks
 * differently and neither convincingly; without it this is an endpoint anybody
 * could use to unsubscribe a stranger's phone.
 */
Route::post('/webhooks/sms/{secret}', InboundSmsController::class)->middleware('throttle:120,1');
