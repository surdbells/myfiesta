const { readFileSync } = require('node:fs');
const { join } = require('node:path');

/**
 * The addresses the site is told at run time, and the template they go into.
 *
 * Neither the API host nor the console host is compiled into the bundle: one
 * build has to serve staging and production, and baking either in is how a
 * staging artifact gets promoted with the wrong host inside it, with nothing
 * about the file showing that it happened.
 *
 * So the server stamps them into the rendered document and the browser reads
 * them back out. That is two files agreeing about the exact shape of a meta
 * tag, with nothing in between — and it has gone wrong twice.
 *
 * Once when the replacement was an exact string: Angular's renderer normalises
 * `content=""` into a bare `content`, so the replace quietly matched nothing
 * and the browser called its own origin for an API that was not there.
 *
 * Once when `console-url` was never stamped at all. The server rendered the
 * right links from its own environment; the browser then hydrated, read
 * `http://localhost:4310` out of the template, and pointed every "Sell
 * tickets" link at the visitor's own machine — the most commercially
 * important links on the site.
 *
 * Both failures are silent, and both send somebody somewhere plausible and
 * wrong. This is the thing in between.
 */

const ROOT = join(__dirname, '..');
const server = readFileSync(join(ROOT, 'apps/web/src/server.ts'), 'utf8');
const template = readFileSync(join(ROOT, 'apps/web/src/index.html'), 'utf8');

/**
 * Every meta tag the template expects somebody to fill in at run time.
 *
 * The three sentry ones say where browser errors are reported and which build
 * this is. Left unstamped, the DSN in the template is empty and reporting is
 * quietly off in production — the same silent failure, in a place nobody
 * looks until the day they need the report.
 */
const STAMPED = ['api-base', 'console-url', 'sentry-dsn', 'sentry-environment', 'sentry-release'];

const problems = [];

/** The pattern the server rewrites with, lifted out of the server itself. */
function patternFor(name) {
  const found = server.match(new RegExp(String.raw`\.replace\(\s*(/<meta name="${name}"[^/]*/)`));

  return found ? new RegExp(found[1].slice(1, -1)) : null;
}

for (const name of STAMPED) {
  if (!new RegExp(`<meta name="${name}"`).test(template)) {
    problems.push(`${name}: the template has no such meta tag for the server to fill in`);
    continue;
  }

  const pattern = patternFor(name);

  if (!pattern) {
    problems.push(`${name}: index.html carries it, and server.ts never rewrites it`);
    continue;
  }

  if (!pattern.test(template)) {
    problems.push(`${name}: the server's pattern does not match the tag as written`);
    continue;
  }

  // What the renderer actually hands back: an empty value comes out bare.
  const rendered = template.replace(
    new RegExp(`<meta name="${name}" content="[^"]*" ?/?>`),
    `<meta name="${name}" content>`,
  );

  if (!pattern.test(rendered)) {
    problems.push(`${name}: the pattern stops matching once the renderer normalises an empty value`);
    continue;
  }

  const stamped = template.replace(pattern, `<meta name="${name}" content="https://real.example">`);

  if ((stamped.match(new RegExp(`<meta name="${name}"`, 'g')) ?? []).length !== 1) {
    problems.push(`${name}: rewriting it leaves a second, stale tag behind`);
  }
}

for (const variable of ['API_BASE_URL', 'CONSOLE_URL', 'SENTRY_DSN', 'SENTRY_ENVIRONMENT', 'SENTRY_RELEASE']) {
  if (!server.includes(`process.env['${variable}']`)) {
    problems.push(`${variable}: the server never reads it, so a deploy cannot set it`);
  }
}

/*
 * The console is told the same thing, by a third route.
 *
 * It is a static build with no server of its own, so its addresses are
 * stamped into index.html when the container starts. Nothing stamped them
 * until there was a deployment to do it, and the symptom would have been
 * every organizer's console quietly calling 127.0.0.1 for an API on their own
 * laptop.
 */
const consoleTemplate = readFileSync(join(ROOT, 'apps/organizer-web/src/index.html'), 'utf8');
const consoleEntrypoint = readFileSync(join(ROOT, 'ops/docker/console-entrypoint.sh'), 'utf8');

const CONSOLE_STAMPED = ['api-base', 'public-base', 'sentry-dsn', 'sentry-environment', 'sentry-release'];

for (const name of CONSOLE_STAMPED) {
  if (!new RegExp(`<meta name="${name}"`).test(consoleTemplate)) {
    problems.push(`console ${name}: the template has no such meta tag to fill in`);
  } else if (!new RegExp(`stamp ${name}`).test(consoleEntrypoint)) {
    problems.push(`console ${name}: nothing stamps it at start-up, so the console would call localhost in production`);
  }
}

// The policy names wherever errors are reported, and only because the
// entrypoint wrote it: a variable nginx is never given stops nginx starting.
const consolePolicy = readFileSync(join(ROOT, 'ops/docker/console.nginx.conf'), 'utf8');

if (consolePolicy.includes('$console_sentry_origin') && !consoleEntrypoint.includes('$console_sentry_origin')) {
  problems.push('console sentry origin: the policy reads $console_sentry_origin and the entrypoint never writes it, so nginx would not start');
}

// And refuses to start rather than serving a console pointed at nobody.
for (const variable of ['API_BASE_URL', 'PUBLIC_URL']) {
  if (!consoleEntrypoint.includes(`${variable}:-`)) {
    problems.push(`console ${variable}: the entrypoint does not check it, so an unset address would ship as empty`);
  }
}

/*
 * The phone app is told the same thing, by a different route.
 *
 * It has no server to stamp the page while rendering, so the address is
 * written into the built file before it is packaged. Without that step the tag
 * ships empty, the client falls back to the address that means "the machine
 * this emulator runs on", and an app in a store talks to a laptop.
 */
const mobileTemplate = readFileSync(join(ROOT, 'apps/mobile/src/index.html'), 'utf8');
const mobileScripts = JSON.parse(readFileSync(join(ROOT, 'apps/mobile/package.json'), 'utf8')).scripts;

if (!/<meta name="api-base"/.test(mobileTemplate)) {
  problems.push('mobile api-base: the phone app has no such meta tag, so it cannot be told where the API is');
} else if (!/stamp-mobile-api-base/.test(mobileScripts.sync ?? '')) {
  problems.push('mobile api-base: nothing stamps it before cap sync, so a packaged app would ship pointing at localhost');
}

/*
 * And where the public site is, by the same script: the checkout, every link
 * the app shares, and which tapped links are its own. Left to the app, it
 * guesses the site from the API by dropping "api.", which is right for one
 * shape of address and localhost for every other.
 */
const mobileStamp = readFileSync(join(ROOT, 'tools/stamp-mobile-api-base.cjs'), 'utf8');

if (!/<meta name="site-base"/.test(mobileTemplate)) {
  problems.push('mobile site-base: the phone app has no such meta tag, so it cannot be told where the public site is');
} else if (!/'site-base'/.test(mobileStamp) || !mobileStamp.includes(`address('PUBLIC_URL')`)) {
  problems.push('mobile site-base: stamp-mobile-api-base.cjs does not fill it from PUBLIC_URL, so the app guesses the site from the API address');
}

/*
 * And whatever address it is given has to come back out of the page as that
 * address. The URL parser keeps a `"` in a host name and `$&` or `$'` in a
 * path. Pasted into the tag as they stand, the first ends the attribute early
 * and the others have replace() copy the tag itself, or the rest of the page,
 * into it. So the real stamp is run over the template with exactly those.
 */
const { address, stamp } = require(join(ROOT, 'tools/stamp-mobile-api-base.cjs'));
const AWKWARD = ['https://my"fiesta.ca', 'https://myfiesta.ca/$&', "https://myfiesta.ca/$'x", 'https://myfiesta.ca/a&amp;b'];

for (const name of ['api-base', 'site-base']) {
  const tag = new RegExp(`<meta name="${name}"[^>]*>`);
  const filled = new RegExp(`<meta name="${name}" content="([^"]*)">`);

  if (!tag.test(mobileTemplate)) continue;

  for (const raw of AWKWARD) {
    const value = address(name, raw);
    const page = stamp(mobileTemplate, name, value, 'the address is');
    const read = page
      .match(filled)?.[1]
      .replace(/&quot;/g, '"')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&amp;/g, '&');

    if (read !== value || page.replace(filled, '') !== mobileTemplate.replace(tag, '')) {
      problems.push(`mobile ${name}: stamping ${raw} does not read back as that address, or changes the page around the tag`);
      break;
    }
  }
}

// Where its errors go, and which store build it is: written in the same way.
const MOBILE_SENTRY = ['sentry-dsn', 'sentry-environment', 'sentry-release'];

for (const name of MOBILE_SENTRY) {
  if (!new RegExp(`<meta name="${name}"`).test(mobileTemplate)) {
    problems.push(`mobile ${name}: the phone app has no such meta tag, so it cannot be told where errors go`);
  }
}

if (!/stamp-mobile-sentry/.test(mobileScripts.sync ?? '')) {
  problems.push('mobile sentry: nothing stamps it before cap sync, so a packaged app would report no errors anywhere');
}

if (problems.length > 0) {
  console.error('\nruntime config: the page and the server disagree\n');

  for (const problem of problems) console.error(`  ${problem}`);

  console.error(
    '\nThe browser reads these out of the rendered document. A tag the server\n' +
      'does not fill in is a link or a request pointing at localhost in production.\n'
  );

  process.exit(1);
}

console.log(
  `runtime config: ${STAMPED.length + CONSOLE_STAMPED.length + 2 + MOBILE_SENTRY.length} values stamped into a page and read back out, across the site, the console and the phone app`,
);
