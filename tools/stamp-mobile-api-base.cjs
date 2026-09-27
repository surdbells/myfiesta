const { readFileSync, writeFileSync, existsSync } = require('node:fs');
const { join } = require('node:path');

/**
 * Writes the API's address, and the public site's, into the phone app's
 * built page.
 *
 *   API_BASE_URL=https://api.myfiesta.ca PUBLIC_URL=https://myfiesta.ca node tools/stamp-mobile-api-base.cjs
 *
 * The site can stamp this while rendering, because the site has a server. A
 * packaged app has nothing between the build and the store, so the value has
 * to be written into the file that gets packaged — and until this existed,
 * nothing wrote it at all.
 *
 * What that meant: the meta tag ships empty, the client falls back to
 * `10.0.2.2` on Android and `127.0.0.1` on iOS — the addresses that mean "the
 * machine this emulator is running on" — and an app built for the store talks
 * to a laptop that is not there. It runs perfectly in development right up to
 * the moment it is real.
 *
 * Runs after `ng build` and before `cap sync`, so whatever is copied into the
 * native projects already knows where it is pointing.
 *
 * The site's address goes in beside it, from PUBLIC_URL — the name the API
 * and the console already give it. The app opens the checkout there, builds
 * every link it shares from it, and decides by it which tapped links are its
 * own to open. Without it the app guesses, by dropping "api." from the API's
 * address: right for api.myfiesta.ca, and for any other API host an app that
 * sends buyers to http://localhost:4320 and ignores every link it was
 * installed to open. So that combination is refused here, before packaging,
 * rather than found by somebody tapping "Get tickets".
 *
 * With neither set this says so and changes nothing, because that is the
 * ordinary case: a developer building for an emulator wants the fallbacks.
 */

const ROOT = join(__dirname, '..');
const PAGE = join(ROOT, 'apps/mobile/dist/mobile/browser/index.html');

// Run, it stamps the built page. Required, it only lends address() and
// stamp() to tools/check-runtime-config.cjs, which stamps a copy of the
// template with awkward addresses and reads them back out.
if (require.main === module) main();

module.exports = { address, stamp };

function main() {
  const api = address('API_BASE_URL');
  const site = address('PUBLIC_URL');

  if (!existsSync(PAGE)) {
    fail(`mobile: no built page at ${PAGE}\n\nRun the build first: npm run build --workspace mobile`);
  }

  if (!api && !site) {
    console.log(
      'mobile: no API_BASE_URL set, so the packaged app will use the development fallback\n' +
        '        (10.0.2.2 on Android, 127.0.0.1 on iOS — the machine the emulator runs on).\n' +
        '        Set API_BASE_URL and PUBLIC_URL before building anything anybody else will install.',
    );

    process.exit(0);
  }

  /*
   * What the app does with no site address (Discover.siteBase in
   * apps/mobile/src/app/core/discovery.ts), worked out the same way, so a
   * build it would get wrong never reaches a store. Plain http is a
   * developer's machine on the local network — a phone on the desk, not in a
   * store — and is told rather than stopped.
   */
  const guessable = !api || site || api.includes('//api.');

  if (!guessable && api.startsWith('https:')) {
    fail(
      `mobile: ${api} is not an api.<site> address, so the app cannot work out where the\n` +
        'public site is from it, and would send buyers to http://localhost:4320.\n\n' +
        "Set PUBLIC_URL to the site's address as well, e.g. PUBLIC_URL=https://myfiesta.ca",
    );
  }

  let page = readFileSync(PAGE, 'utf8');

  if (api) page = stamp(page, 'api-base', api, 'the API is');
  if (site) page = stamp(page, 'site-base', site, 'the public site is');

  writeFileSync(PAGE, page);

  console.log(
    api
      ? `mobile: packaged app will talk to ${api}`
      : 'mobile: no API_BASE_URL set, so the packaged app will use the development fallback',
  );
  console.log(
    site
      ? `mobile: packaged app opens the checkout and its links on ${site}`
      : guessable
        ? `mobile: no PUBLIC_URL set, so the packaged app takes ${api.replace('//api.', '//')} for the site`
        : 'mobile: no PUBLIC_URL set, so the packaged app sends the checkout and its links to http://localhost:4320.\n' +
          '        Set PUBLIC_URL to the site this phone can reach.',
  );
}

/**
 * One address from the environment, as the app will read it: http or https,
 * no query, no trailing slash. Parsed so a typo is refused here rather than
 * found on a phone. Parsing does not make it safe to write into the page,
 * though; stamp() does that.
 */
function address(name, given = process.env[name]) {
  const raw = (given ?? '').trim();

  if (raw === '') return null;

  let url;

  try {
    url = new URL(raw);
  } catch {
    fail(`mobile: ${name} is not a web address: ${raw}\n\nIt wants the whole address, e.g. https://myfiesta.ca`);
  }

  if ((url.protocol !== 'https:' && url.protocol !== 'http:') || url.search !== '' || url.hash !== '' || url.username !== '') {
    fail(`mobile: ${name} should be a plain http or https address with nothing after the path: ${raw}`);
  }

  return (url.origin + url.pathname).replace(/\/+$/, '');
}

/**
 * Fills one tag in with one address.
 *
 * A parsed address can still carry things that mean something in the page:
 * the URL parser keeps a `"` in a host name, and `&` and `$` in a path. So the
 * value is escaped for the attribute, which the app's `meta.content` reads
 * back as exactly the address it was. And the tag goes to replace() through a
 * function, because in a replacement string `$&` and `$'` mean "the tag that
 * matched" and "the rest of the page", and would copy either into the value.
 */
function stamp(page, name, value, what) {
  const tag = new RegExp(`<meta name="${name}"[^>]*>`);

  if (!tag.test(page)) {
    fail(
      `mobile: the built page has no ${name} meta tag to fill in.\n\n` +
        'It is in apps/mobile/src/index.html; something has removed or renamed it,\n' +
        `and without it the app has no way to be told where ${what}.`,
    );
  }

  const content = value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  return page.replace(tag, () => `<meta name="${name}" content="${content}">`);
}

function fail(message) {
  console.error(`\n${message}\n`);
  process.exit(1);
}
