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
const allowedHosts = (process.env['ALLOWED_HOSTS'] ?? 'myfiesta.ca,www.myfiesta.ca,localhost,127.0.0.1')
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

app.use((req, res, next) => {
  angularApp
    .handle(req)
    .then((response) =>
      response ? writeResponseToNodeResponse(response, res) : next(),
    )
    .catch(next);
});

if (isMainModule(import.meta.url) || process.env['pm_id']) {
  const port = process.env['PORT'] || 4000;
  app.listen(port, (error) => {
    if (error) {
      throw error;
    }

    console.log(`myFiesta web listening on http://localhost:${port}`);
    console.log(`API: ${process.env['API_URL'] ?? 'http://localhost:8000'}`);
  });
}

export const reqHandler = createNodeRequestHandler(app);
