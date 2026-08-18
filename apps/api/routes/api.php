<?php

use Illuminate\Support\Facades\Route;

/*
 * API routes.
 *
 * Endpoints arrive in Phase 2 with the checkout work. What is fixed now is the
 * shape every authenticated route follows:
 *
 *   Route::middleware(['auth:sanctum', 'token.scope:organizer'])->group(...)
 *   Route::middleware(['auth:sanctum', 'token.scope:door'])->post('/events/{event}/scan', ...)
 *
 * Public read endpoints carry no token requirement — browsing and buying need
 * no account.
 */

Route::get('/health', fn () => ['status' => 'ok']);
