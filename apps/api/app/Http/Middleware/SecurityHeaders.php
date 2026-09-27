<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response from this application carries.
 *
 * Set here rather than in nginx so they are the same under `artisan serve`
 * as in production, and so a test can hold them in place. The console and
 * the public site are other origins with their own servers; their policies
 * are in ops/docker/console.nginx.conf and apps/web/src/security-headers.ts.
 *
 * Nothing this application serves is meant to be framed — not the JSON, not
 * the admin panel, not the pages behind the links in emails — so all of it
 * refuses, both ways: X-Frame-Options for older browsers and frame-ancestors
 * for the rest. The one page that must be framable, the embedded checkout,
 * belongs to the public site.
 */
class SecurityHeaders
{
    /**
     * Powerful features, none of which anything here uses.
     *
     * Listed so a script that somehow ran on one of these pages could not ask
     * for the camera or a payment sheet either.
     */
    private const PERMISSIONS = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    /**
     * JSON, CSV, a calendar file: nothing that should ever run or load anything.
     *
     * If a browser were ever talked into rendering one of these as a page, it
     * would render nothing with it.
     */
    private const API = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /**
     * The plain pages behind emailed links: unsubscribing, leaving a waitlist,
     * a privacy request, confirming an address.
     *
     * They are one inline stylesheet and a form that posts back here, and are
     * allowed exactly that. Each carries a token in its address, so nothing
     * else loading on them is also nothing else to leak it to.
     */
    private const PAGES = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

    /**
     * The admin panel: Livewire and Alpine, which evaluate their own
     * expressions and inline their own scripts. A script policy tight enough
     * to matter would break it, so this one only refuses framing and the
     * tags nothing here uses — the panel is behind a staff login, and
     * clickjacking a signed-in member of staff is the attack worth closing.
     */
    private const PANEL = "frame-ancestors 'none'; base-uri 'self'; object-src 'none'";

    /**
     * A file opened in its own tab: an identity document on review, a data
     * export.
     *
     * Nothing in one may run — an uploaded "image" that is really an SVG with
     * a script in it would otherwise run on this origin, next to a member of
     * staff's session. But no default-src: a browser shows a PDF through a
     * viewer the page's policy would otherwise refuse to load.
     */
    private const FILES = "script-src 'none'; frame-ancestors 'none'; base-uri 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        // Tokens travel in the paths of emailed links. Same-origin navigation
        // keeps the full address, which the panel's back links rely on; every
        // other origin is told nothing, so following a link out of one of
        // those pages does not hand its token to wherever it leads.
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', self::PERMISSIONS);

        $policy = $this->policyFor($request, $response);

        if ($policy !== null && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $policy);
        }

        // Only over https, which is only known once the proxy in front has
        // been believed (TRUSTED_PROXIES). A browser ignores it over http in
        // any case; sending it there would only be noise.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policyFor(Request $request, Response $response): ?string
    {
        if ($request->is('api', 'api/*')) {
            return self::API;
        }

        if ($request->is('admin', 'admin/*', 'livewire/*', 'filament/*', 'up')) {
            return self::PANEL;
        }

        // The error page a developer sees with APP_DEBUG on runs its own
        // scripts. Production never shows it — Preflight refuses debug there.
        if (config('app.debug') && $response->getStatusCode() >= 500) {
            return self::PANEL;
        }

        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return self::FILES;
        }

        return self::PAGES;
    }
}
