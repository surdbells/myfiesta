<?php

use App\Http\Middleware\EnforceTokenScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applied per route as token.scope:attendee|organizer|door. The mobile
        // app carries all three modes in one binary, so this — not the UI — is
        // what keeps a door-staff phone away from sales and payouts.
        $middleware->alias([
            'token.scope' => EnforceTokenScope::class,
        ]);

        // Webhooks carry no browser session, so there is no CSRF token to
        // present and nothing for one to protect. Their authentication is the
        // signature over the raw body, checked inside each gateway adapter.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
