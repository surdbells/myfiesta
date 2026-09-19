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

/** Every meta tag the template expects somebody to fill in at run time. */
const STAMPED = ['api-base', 'console-url'];

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

for (const variable of ['API_BASE_URL', 'CONSOLE_URL']) {
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

for (const name of ['api-base', 'public-base']) {
  if (!new RegExp(`<meta name="${name}"`).test(consoleTemplate)) {
    problems.push(`console ${name}: the template has no such meta tag to fill in`);
  } else if (!new RegExp(`stamp ${name}`).test(consoleEntrypoint)) {
    problems.push(`console ${name}: nothing stamps it at start-up, so the console would call localhost in production`);
  }
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

if (problems.length > 0) {
  console.error('\nruntime config: the page and the server disagree\n');

  for (const problem of problems) console.error(`  ${problem}`);

  console.error(
    '\nThe browser reads these out of the rendered document. A tag the server\n' +
      'does not fill in is a link or a request pointing at localhost in production.\n'
  );

  process.exit(1);
}

console.log(`runtime config: ${STAMPED.length + 3} addresses stamped into a page and read back out, across the site, the console and the phone app`);
