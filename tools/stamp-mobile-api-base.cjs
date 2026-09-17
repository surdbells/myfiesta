const { readFileSync, writeFileSync, existsSync } = require('node:fs');
const { join } = require('node:path');

/**
 * Writes the API address into the phone app's built page.
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
 * With no API_BASE_URL set this says so and changes nothing, because that is
 * the ordinary case: a developer building for an emulator wants the fallback.
 */

const ROOT = join(__dirname, '..');
const PAGE = join(ROOT, 'apps/mobile/dist/mobile/browser/index.html');

const address = process.env['API_BASE_URL'];

if (!existsSync(PAGE)) {
  console.error(`\nmobile: no built page at ${PAGE}\n\nRun the build first: npm run build --workspace mobile\n`);
  process.exit(1);
}

if (!address) {
  console.log(
    'mobile: no API_BASE_URL set, so the packaged app will use the development fallback\n' +
      '        (10.0.2.2 on Android, 127.0.0.1 on iOS — the machine the emulator runs on).\n' +
      '        Set API_BASE_URL before building anything anybody else will install.',
  );

  process.exit(0);
}

const page = readFileSync(PAGE, 'utf8');
const tag = /<meta name="api-base"[^>]*>/;

if (!tag.test(page)) {
  console.error(
    '\nmobile: the built page has no api-base meta tag to fill in.\n\n' +
      'It is in apps/mobile/src/index.html; something has removed or renamed it,\n' +
      'and without it the app has no way to be told where the API is.\n',
  );

  process.exit(1);
}

writeFileSync(PAGE, page.replace(tag, `<meta name="api-base" content="${address}">`));

console.log(`mobile: packaged app will talk to ${address}`);
