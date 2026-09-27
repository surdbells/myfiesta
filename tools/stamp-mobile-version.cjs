const { readFileSync, writeFileSync } = require('node:fs');
const { join } = require('node:path');

/**
 * Writes the phone app's version into both native projects.
 *
 *   node tools/stamp-mobile-version.cjs           write it
 *   node tools/stamp-mobile-version.cjs --check   fail if either project disagrees
 *
 * The version lives in one place, apps/mobile/package.json: `version` is what
 * people see in the store ("18.0.0") and `buildNumber` is what the stores
 * compare. Before this each native project carried its own — 1.0 and build 1
 * in both, below the live app — and a store rejects an update whose number is
 * not above the one it already has. Nothing would have said so until the
 * upload.
 *
 * `buildNumber` goes up by one for every upload to either store, whatever the
 * version. Google Play refuses a versionCode it has ever seen, on any track;
 * the App Store refuses a repeated build within a version. One integer that
 * only climbs satisfies both.
 *
 * Runs in `npm run sync`, after the build and before `cap sync`, so the
 * projects Android Studio and Xcode open already carry the right numbers. It
 * changes the files only when they disagree, so a sync with nothing new to
 * say leaves git clean.
 */

const ROOT = join(__dirname, '..');
const MANIFEST = join(ROOT, 'apps/mobile/package.json');
const GRADLE = join(ROOT, 'apps/mobile/android/app/build.gradle');
const XCODE = join(ROOT, 'apps/mobile/ios/App/App.xcodeproj/project.pbxproj');

/** Google Play's ceiling for versionCode. */
const MAX_BUILD = 2100000000;

const check = process.argv.includes('--check');
const { version, buildNumber } = JSON.parse(readFileSync(MANIFEST, 'utf8'));

// Three plain numbers: what both stores accept as a version string, and what
// Apple compares component by component.
if (!/^\d+\.\d+\.\d+$/.test(version ?? '')) {
  fail(`apps/mobile/package.json: "version" must be three numbers, like 18.0.0 — it is ${JSON.stringify(version)}`);
}

if (!Number.isInteger(buildNumber) || buildNumber < 1 || buildNumber > MAX_BUILD) {
  fail(`apps/mobile/package.json: "buildNumber" must be a whole number from 1 to ${MAX_BUILD} — it is ${JSON.stringify(buildNumber)}`);
}

const targets = [
  {
    file: GRADLE,
    name: 'android/app/build.gradle',
    rules: [
      [/versionCode\s+\d+/g, `versionCode ${buildNumber}`],
      [/versionName\s+"[^"]*"/g, `versionName "${version}"`],
    ],
  },
  {
    file: XCODE,
    name: 'ios/App/App.xcodeproj',
    rules: [
      [/MARKETING_VERSION = [^;]*;/g, `MARKETING_VERSION = ${version};`],
      [/CURRENT_PROJECT_VERSION = [^;]*;/g, `CURRENT_PROJECT_VERSION = ${buildNumber};`],
    ],
  },
];

const stale = [];

for (const { file, name, rules } of targets) {
  const before = readFileSync(file, 'utf8');
  let after = before;

  for (const [pattern, value] of rules) {
    // A project with no such line would be stamped with nothing and still
    // look fine; the store would then read whatever default Xcode or Gradle
    // picked. Missing is a failure, not a no-op.
    if (after.search(pattern) === -1) fail(`apps/mobile/${name}: found no ${value.split(/[\s=]/)[0]} to set`);

    after = after.replace(pattern, value);
  }

  if (after === before) continue;

  stale.push(name);
  if (!check) writeFileSync(file, after);
}

if (check && stale.length > 0) {
  fail(
    `mobile: ${stale.join(' and ')} ${stale.length > 1 ? 'do' : 'does'} not carry version ${version} (${buildNumber}) ` +
      'from apps/mobile/package.json.\n\nRun: node tools/stamp-mobile-version.cjs',
  );
}

console.log(
  stale.length === 0 || check
    ? `mobile: version ${version} (build ${buildNumber}), already in both native projects`
    : `mobile: version ${version} (build ${buildNumber}) written to ${stale.join(' and ')}`,
);

function fail(message) {
  console.error(`\n${message}\n`);
  process.exit(1);
}
