<?php

use App\Http\Controllers\IdentityDocumentController;
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
