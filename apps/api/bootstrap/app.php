<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnforceTokenScope;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\ImpersonationBoundary;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SuspensionBoundary;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as BasePreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

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
            'api.key' => AuthenticateApiKey::class,
            // An address somebody has proved they read, for the few actions
            // that act in public or move money. See the class for which.
            'verified.email' => EnsureEmailIsVerified::class,
        ]);

        // myFiesta staff acting as an organization: on the whole api group,
        // so an endpoint added later is inside the boundary without anybody
        // listing it. Inert for every token that is not a staff session's.
        $middleware->appendToGroup('api', ImpersonationBoundary::class);

        // A suspended organization: publishing, selling and asking to be
        // paid are refused with a sentence that says why. Inert for every
        // endpoint WhileSuspended does not name.
        $middleware->appendToGroup('api', SuspensionBoundary::class);

        /*
         * Who a request came from, believed only from the proxies named in
         * TRUSTED_PROXIES (config/trustedproxy.php).
         *
         * The address, the scheme and the port — what a load balancer in
         * front of TLS actually knows. Not X-Forwarded-Host: the Host header
         * already arrives intact through every proxy this runs behind, and a
         * second, forwardable copy of it is only a way to put another site's
         * name into the links this application builds.
         */
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Outermost, so every response carries them — including the ones the
        // framework's own middleware answers before a route is reached.
        $middleware->prepend(SecurityHeaders::class);

        // The framework's, except that the readiness check still answers when
        // Redis, where maintenance mode is kept, is down. See the class.
        $middleware->replace(BasePreventRequestsDuringMaintenance::class, PreventRequestsDuringMaintenance::class);

        // Webhooks carry no browser session, so there is no CSRF token to
        // present and nothing for one to protect. Their authentication is the
        // signature over the raw body, checked inside each gateway adapter.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
            // Same arrangement for inbound texts: no session, and the
            // secret in the path is the authorisation.
            'webhooks/sms/*',
            // Gmail and Outlook post here directly when they render their own
            // unsubscribe control, from the List-Unsubscribe-Post header. Those
            // requests carry no session and no token, and the token in the URL
            // is the authorisation. Requiring CSRF would break the one path the
            // law is most specific about.
            'unsubscribe/*',
            // The links that finish a sign-up and prove an address. The signed
            // link is the whole credential, and the only thing a forged post
            // could do with one is what its holder would have done anyway.
            // A session cookie is exactly what a mail client's own browser is
            // likeliest to lose, and a "page expired" there is a sign-up lost.
            'sign-up/*',
            'verify-email/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every reported exception to Sentry, through SentryScrubber on the
        // way (config/sentry.php). With SENTRY_LARAVEL_DSN empty — tests,
        // development — the SDK has nowhere to send it and nothing leaves.
        Integration::handles($exceptions);
    })->create();
