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

if (problems.length > 0) {
  console.error('\nruntime config: the page and the server disagree\n');

  for (const problem of problems) console.error(`  ${problem}`);

  console.error(
    '\nThe browser reads these out of the rendered document. A tag the server\n' +
      'does not fill in is a link or a request pointing at localhost in production.\n'
  );

  process.exit(1);
}

console.log(`runtime config: ${STAMPED.length} addresses stamped into the page and read back out`);
