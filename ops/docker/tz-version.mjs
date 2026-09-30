import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Which edition of the time-zone database this Node reads, and one night in it.
 *
 *   node tz-version.mjs          says which
 *   node tz-version.mjs 2026d    and fails unless it is 2026d
 *
 * In the site's image it is /app/tz-version.mjs, and the build runs it with
 * the edition in ops/docker/tzdata-edition. `php artisan app:time-zones` is
 * the API's half of the same check: the two have to name the same edition,
 * or the site and the API put the same night at different times
 * (docs/OPERATIONS.md, "Time zones").
 *
 * The night is Vancouver after British Columbia stopped changing its clocks:
 * the difference that showed the editions had drifted. Both halves print it
 * the same way, so the two lines can be compared by eye.
 */

const expected = process.argv[2] ?? null;
const edition = process.versions.tz ?? 'unknown';

// ICU reads zone files from this folder in place of its own copy when they are
// there, and falls back to its own without a word when they are not — which
// is why the build checks the edition rather than trusting the folder.
const folder = process.env.ICU_TIMEZONE_FILES_DIR;

/**
 * The edition Node carries itself, from a Node started without the folder.
 *
 * Node reports only the edition it ended up with, not where it came from, so
 * the folder is named only when the answer differs from this: a folder that
 * was missing, empty or unreadable leaves Node on its own copy, and saying
 * otherwise would hide the one thing this is run to find.
 */
function ownEdition() {
  const env = { ...process.env };
  delete env.ICU_TIMEZONE_FILES_DIR;

  try {
    return execFileSync(process.execPath, ['-p', 'process.versions.tz'], { env, encoding: 'utf8' }).trim();
  } catch {
    return null;
  }
}

function source() {
  const own = `the copy inside Node ${process.version}`;

  if (!folder) return own;
  if (!existsSync(join(folder, 'zoneinfo64.res'))) return `${own} (there are no zone files in ${folder})`;
  if (ownEdition() !== edition) return `ICU's zone files in ${folder}`;

  return `${own} (the files in ${folder} are the same edition, or could not be read)`;
}

const night = new Intl.DateTimeFormat('en-GB', {
  timeZone: 'America/Vancouver',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
  timeZoneName: 'longOffset',
}).formatToParts(new Date(Date.UTC(2026, 10, 15, 4)));

const part = (type) => night.find((p) => p.type === type)?.value ?? '?';
const offset = part('timeZoneName').replace(/^GMT/, 'UTC');

console.log(`Node reads time zones from ${source()}: IANA's ${edition}.`);
console.log(`Vancouver at 04:00 UTC on 15 November 2026: ${part('hour')}:${part('minute')}, ${offset === 'UTC' ? 'UTC+00:00' : offset}.`);

if (expected !== null && expected !== edition) {
  console.error(`That is not ${expected}.`);
  process.exit(1);
}
