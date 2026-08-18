<?php

use App\Http\Controllers\IdentityDocumentController;
use App\Http\Controllers\OrderController;
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
