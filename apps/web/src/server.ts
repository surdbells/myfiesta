import {
  AngularNodeAppEngine,
  createNodeRequestHandler,
  isMainModule,
  writeResponseToNodeResponse,
} from '@angular/ssr/node';
import express from 'express';
import { join } from 'node:path';

const browserDistFolder = join(import.meta.dirname, '../browser');

const app = express();

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

app.use((req, res, next) => {
  angularApp
    .handle(req)
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
      const html = (await response.text()).replace(
        /<meta name="api-base"[^>]*>/,
        `<meta name="api-base" content="${apiBaseUrl}">`,
      );

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
