import {
  AngularNodeAppEngine,
  createNodeRequestHandler,
  isMainModule,
  writeResponseToNodeResponse,
} from '@angular/ssr/node';
import { isDevMode } from '@angular/core';
import express from 'express';
import { randomBytes } from 'node:crypto';
import { join } from 'node:path';
import { securityHeaders } from './security-headers';
import { errorReportingOrigin as errorReportingOriginOf } from '@myfiesta/shared/error-reporting';

const browserDistFolder = join(import.meta.dirname, '../browser');

const app = express();

// Nothing about the server, to anybody.
app.disable('x-powered-by');

/**
 * A nonce for this response, and the headers that name it.
 *
 * First, so every response carries them — rendered pages, files and errors
 * alike. The nonce goes to Angular with the request (see the handler at the
 * bottom) and onto the inline scripts it writes into the page; see
 * app.config.server.ts. Nothing but those may run inline.
 */
app.use((req, res, next) => {
  const nonce = randomBytes(16).toString('base64');
  res.locals['cspNonce'] = nonce;

  const headers = securityHeaders(req.path, {
    nonce,
    apiOrigin,
    errorReportingOrigin,
    devServerHost: isDevMode() ? req.get('host') : undefined,
    // Believed only to decide whether to insist on https, which a browser
    // ignores over plain http anyway: a client that lies here fools itself.
    https: req.get('x-forwarded-proto') === 'https',
  });

  for (const [name, value] of Object.entries(headers)) res.setHeader(name, value);
  next();
});

/**
 * The script an organizer pastes into their own site.
 *
 * Not content-hashed — its address is in other people's HTML and cannot
 * change — so it gets an hour, not the year the built assets get. A fix to it
 * then reaches every venue site the same afternoon.
 */
app.get('/embed.js', (_req, res, next) => {
  res.set('Cache-Control', 'public, max-age=3600');
  res.set('Access-Control-Allow-Origin', '*');
  next();
});

/**
 * The two files that let the phone app open this site's links.
 *
 * Apple and Google fetch these from /.well-known/ before a tap on an event
 * link will open the app instead of this site (docs/STORE.md). Both insist on
 * JSON at exactly this address — no redirect — and the Apple one has no
 * extension to take a type from. The static handler below would not serve
 * them at all: it ignores any path through a dot-directory. So they are
 * answered here, by name, and nothing else under /.well-known/ is.
 *
 * An hour's cache: long enough for their crawlers, short enough that a fixed
 * fingerprint is live the same afternoon.
 */
for (const name of ['apple-app-site-association', 'assetlinks.json']) {
  app.get(`/.well-known/${name}`, (_req, res, next) => {
    res.type('application/json');
    res.sendFile(
      join(browserDistFolder, '.well-known', name),
      { dotfiles: 'allow', maxAge: '1h' },
      (error) => error && next(error),
    );
  });
}

/**
 * Hosts this server will render for.
 *
 * Angular refuses to render for an unrecognised Host header, because an
 * attacker who can set it could make server-side requests resolve against a
 * host of their choosing. The failure is quiet rather than loud — it falls back
 * to client-side rendering, so pages still load for people and arrive empty at
 * a crawler. That is precisely the failure this app exists to avoid, so the
 * allowlist is explicit rather than left to a default.
 */
const allowedHosts = (
  process.env['ALLOWED_HOSTS'] ?? 'myfiesta.ca,www.myfiesta.ca,localhost,127.0.0.1'
)
  .split(',')
  // Hostnames only — Angular compares the parsed hostname, so an entry that
  // includes a port never matches and the check fails open into client-side
  // rendering, which looks like it works right up until a crawler arrives.
  .map((host) => host.trim().replace(/:\d+$/, ''))
  .filter(Boolean);

const angularApp = new AngularNodeAppEngine({ allowedHosts });

/**
 * Static assets are content-hashed by the build, so a year is safe and anything
 * shorter just costs a round trip.
 */
app.use(
  express.static(browserDistFolder, {
    maxAge: '1y',
    index: false,
    redirect: false,
    setHeaders: (res, path) => {
      if (path.endsWith('embed.js')) res.setHeader('Cache-Control', 'public, max-age=3600');
    },
  }),
);

/**
 * Where the browser half of this app should send its requests.
 *
 * Stamped into the rendered document rather than compiled into the bundle, so
 * one build serves staging and production. Baking it in at build time is how a
 * staging bundle gets promoted with the wrong API host inside it, and nothing
 * about the artifact would show that had happened.
 */
const apiBaseUrl = process.env['API_BASE_URL'] ?? 'http://127.0.0.1:8000';

/**
 * The same, as an origin, for the Content-Security-Policy. A source with a
 * path in it matches that path only, and the site calls everything under it.
 */
const apiOrigin = new URL(apiBaseUrl).origin;

/**
 * Where the organizer console lives, stamped for the same reason.
 *
 * This one was being read from the document by the browser and never written
 * into it: the server rendered the right hrefs from its own environment, and
 * then the browser hydrated, read `content="http://localhost:4310"` out of the
 * template, and pointed every "Sell tickets" link at the visitor's own
 * machine. Which are the most commercially important links on the site.
 */
const consoleUrl = process.env['CONSOLE_URL'] ?? 'http://localhost:4310';

/**
 * Where the browser reports errors, and what it labels them with — stamped
 * for the same reason again (core/error-reporting.ts). Empty means the page
 * reports nothing and never loads the reporter. The release is the one the
 * image was built with (ops/docker/web.Dockerfile).
 */
const sentryDsn = (process.env['SENTRY_DSN'] ?? '').trim();
const sentryEnvironment = (process.env['SENTRY_ENVIRONMENT'] ?? '').trim();
const sentryRelease = (process.env['SENTRY_RELEASE'] ?? '').trim();

/** The same, as an origin the policy lets the browser send reports to. */
const errorReportingOrigin = errorReportingOriginOf(sentryDsn) ?? undefined;

/** A value going into an attribute: nothing that could close it. */
function attribute(value: string): string {
  return value.replace(/[&"<>]/g, '');
}

/**
 * What crawlers may read.
 *
 * Event pages and the listing, yes — they are the storefront. A buyer's
 * tickets and order pages, no: the address is the credential and the page
 * carries names and scannable codes. They are also marked noindex, since a
 * disallow alone still lets a search engine list a URL it saw linked
 * somewhere without reading it.
 */
const PRIVATE_PATHS = ['/tickets/', '/order/', '/sign-in', '/register', '/login', '/embed/'];

app.get('/robots.txt', (req, res) => {
  const origin = siteOrigin(req);

  res
    .type('text/plain')
    .set('Cache-Control', 'public, max-age=86400')
    .send(
      [
        'User-agent: *',
        ...PRIVATE_PATHS.map((path) => `Disallow: ${path}`),
        // Checkout steps: every event has them, and none is worth a result.
        'Disallow: /*/checkout',
        '',
        `Sitemap: ${origin}/sitemap.xml`,
        '',
      ].join('\n'),
    );
});

/**
 * The sitemap, from the API, on this origin.
 *
 * Crawlers look for it here, and a sitemap may only list URLs on its own
 * host — so the API builds it and the site serves it.
 */
app.get('/sitemap.xml', async (_req, res) => {
  try {
    const upstream = await fetch(`${apiBaseUrl}/api/sitemap.xml`, { signal: AbortSignal.timeout(10_000) });

    if (!upstream.ok) {
      res.status(502).type('text/plain').send('Sitemap unavailable.');
      return;
    }

    res
      .status(200)
      .type('application/xml')
      .set('Cache-Control', 'public, max-age=3600')
      .send(await upstream.text());
  } catch {
    res.status(502).type('text/plain').send('Sitemap unavailable.');
  }
});

/** Private pages carry the header as well as the robots.txt rule. */
app.use((req, res, next) => {
  if (PRIVATE_PATHS.some((path) => req.path.startsWith(path)) || /^\/[^/]+\/checkout/.test(req.path)) {
    res.setHeader('X-Robots-Tag', 'noindex, nofollow');
  }

  next();
});

/**
 * This site's own origin, for the robots.txt sitemap line.
 *
 * PUBLIC_URL when set. Otherwise the request's host — but only one on the
 * allowlist, so a forged Host header cannot point crawlers at another site's
 * sitemap from ours.
 */
function siteOrigin(req: express.Request): string {
  const configured = process.env['PUBLIC_URL'];
  if (configured) return configured.replace(/\/+$/, '');

  const host = req.get('host') ?? '';
  const hostname = host.replace(/:\d+$/, '');

  return allowedHosts.includes(hostname) ? `${req.protocol}://${host}` : 'https://myfiesta.ca';
}

app.use((req, res, next) => {
  angularApp
    // The nonce the first middleware put in this response's policy, for the
    // inline scripts Angular is about to write (app.config.server.ts).
    .handle(req, { cspNonce: res.locals['cspNonce'] })
    .then(async (response) => {
      if (!response) {
        return next();
      }

      // Only HTML carries the tag; everything else passes through untouched.
      const contentType = response.headers.get('content-type') ?? '';

      if (!contentType.includes('text/html')) {
        return writeResponseToNodeResponse(response, res);
      }

      // Matched by pattern rather than exact string: Angular's renderer
      // normalises an empty attribute, so `content=""` comes back out as a
      // bare `content`. An exact replace silently does nothing, and the
      // symptom is a browser calling its own origin for an API that is not
      // there — which is precisely the bug this replaced.
      const html = (await response.text())
        .replace(/<meta name="api-base"[^>]*>/, `<meta name="api-base" content="${apiBaseUrl}">`)
        .replace(/<meta name="console-url"[^>]*>/, `<meta name="console-url" content="${consoleUrl}">`)
        .replace(/<meta name="sentry-dsn"[^>]*>/, `<meta name="sentry-dsn" content="${attribute(sentryDsn)}">`)
        .replace(/<meta name="sentry-environment"[^>]*>/, `<meta name="sentry-environment" content="${attribute(sentryEnvironment)}">`)
        .replace(/<meta name="sentry-release"[^>]*>/, `<meta name="sentry-release" content="${attribute(sentryRelease)}">`);

      res.status(response.status);
      response.headers.forEach((value, key) => {
        // Length changed with the substitution; letting Express recompute it
        // avoids a truncated body.
        if (key.toLowerCase() !== 'content-length') {
          res.setHeader(key, value);
        }
      });

      return res.send(html);
    })
    .catch(next);
});

if (isMainModule(import.meta.url) || process.env['pm_id']) {
  const port = process.env['PORT'] || 4000;
  app.listen(port, (error) => {
    if (error) {
      throw error;
    }

    console.log(`myFiesta web listening on http://localhost:${port}`);
    console.log(`API: ${apiBaseUrl}`);
  });
}

export const reqHandler = createNodeRequestHandler(app);
