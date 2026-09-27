const { readFileSync, writeFileSync, existsSync } = require('node:fs');
const { join } = require('node:path');

/**
 * Writes where the phone app reports errors, and which build it is, into the
 * built page.
 *
 *   MOBILE_SENTRY_DSN=https://key@o1.ingest.sentry.io/3 node tools/stamp-mobile-sentry.cjs
 *
 * The same arrangement as the API address (stamp-mobile-api-base.cjs): a
 * packaged app has nothing between the build and the store, so the value goes
 * into the file that is packaged. Runs in `npm run sync`, after the build and
 * before `cap sync`.
 *
 * MOBILE_SENTRY_DSN, not SENTRY_DSN: the phone app is its own Sentry project,
 * and a shell that already has the API's DSN in it must not quietly send the
 * app's errors there. Unset, the app reports nothing and never loads the
 * reporter — the ordinary case for a developer building for an emulator.
 *
 * The release is the store version and build number from
 * apps/mobile/package.json — "mobile@18.0.0+1800" — so a crash report names
 * exactly the build somebody installed.
 */

const ROOT = join(__dirname, '..');
const PAGE = join(ROOT, 'apps/mobile/dist/mobile/browser/index.html');
const MANIFEST = join(ROOT, 'apps/mobile/package.json');

const dsn = (process.env['MOBILE_SENTRY_DSN'] ?? '').trim();
const environment = (process.env['MOBILE_SENTRY_ENVIRONMENT'] ?? 'production').replace(/[^A-Za-z0-9._-]/g, '');

if (!existsSync(PAGE)) {
  fail(`mobile: no built page at ${PAGE}\n\nRun the build first: npm run build --workspace mobile`);
}

if (dsn !== '' && !/^https:\/\/[A-Za-z0-9]+@[A-Za-z0-9.-]+(:\d+)?\/\d+$/.test(dsn)) {
  fail('mobile: MOBILE_SENTRY_DSN is not a Sentry DSN (https://key@host/project). Leave it unset to report nothing.');
}

const { version, buildNumber } = JSON.parse(readFileSync(MANIFEST, 'utf8'));
const release = `mobile@${version}+${buildNumber}`;

let page = readFileSync(PAGE, 'utf8');

for (const [name, value] of [
  ['sentry-dsn', dsn],
  ['sentry-environment', dsn === '' ? '' : environment],
  ['sentry-release', dsn === '' ? '' : release],
]) {
  const tag = new RegExp(`<meta name="${name}"[^>]*>`);

  if (!tag.test(page)) {
    fail(
      `mobile: the built page has no ${name} meta tag to fill in.\n\n` +
        'It is in apps/mobile/src/index.html; something has removed or renamed it.',
    );
  }

  page = page.replace(tag, `<meta name="${name}" content="${value}">`);
}

writeFileSync(PAGE, page);

console.log(
  dsn === ''
    ? 'mobile: no MOBILE_SENTRY_DSN set, so the packaged app reports no errors anywhere'
    : `mobile: packaged app reports errors as ${release} (${environment})`,
);

function fail(message) {
  console.error(`\n${message}\n`);
  process.exit(1);
}
