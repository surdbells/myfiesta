const { readFileSync, existsSync } = require('node:fs');
const { join } = require('node:path');

/**
 * Every Capacitor plugin the phone app installs, against what each native
 * project actually links.
 *
 * `cap sync` writes these lists. When a plugin cannot be linked it is left out
 * and the sync still reports success — so the app builds, installs, runs, and
 * the one feature that needed the plugin quietly does nothing. The code is
 * right, the tests pass, and it fails only on a real device in somebody's
 * hand.
 *
 * That has already happened here twice over. The iOS project went three
 * plugins behind because a sync was run as `cap sync android`, which does not
 * touch iOS; and the barcode scanner ships a CocoaPods podspec and no
 * `Package.swift`, so it cannot be linked into an SPM project at all. Camera
 * scanning on iPhone did not work, however carefully the scanner was written,
 * until reading moved into the WebView.
 *
 * Run by `npm run check`. It reads files rather than building anything.
 */

const ROOT = join(__dirname, '..');
const MOBILE = join(ROOT, 'apps/mobile');

/** How a package name becomes the name a native project links. */
function nativeName(pkg) {
  return pkg
    .replace(/^@capacitor-mlkit\//, 'CapacitorMlkit-')
    .replace(/^@capacitor\//, 'Capacitor-')
    .split(/[-/]/)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');
}

const manifest = JSON.parse(readFileSync(join(MOBILE, 'package.json'), 'utf8'));

/** Plugins, which is everything Capacitor except the core and the CLI. */
const plugins = Object.keys(manifest.dependencies ?? {}).filter(
  (name) => /capacitor/i.test(name) && !['@capacitor/core', '@capacitor/cli'].includes(name),
);

/**
 * Known, and answered some other way.
 *
 * Listed so that this check stays useful for everything else rather than
 * sitting red — and so the gap is written down somewhere rather than being an
 * absence nobody can see. Each one says what covers for the missing plugin,
 * and docs/DECISIONS.md says why that was chosen over linking it.
 */
const ACCEPTED = new Map([
  [
    '@capacitor-mlkit/barcode-scanning',
    'ios: ships a CocoaPods podspec and no Package.swift, and this project links plugins ' +
      'through SPM, so ML Kit is still not linked on iPhone. Scanning there runs in the ' +
      'WebView instead — the camera through getUserMedia, read by ZXing compiled to ' +
      'WebAssembly and shipped inside the app. Android keeps ML Kit. See docs/DECISIONS.md.',
  ],
]);

const problems = [];
const accepted = [];
const notes = [];

// --- iOS: a Swift package manifest listing each linked product ---------------

const spm = join(MOBILE, 'ios/App/CapApp-SPM/Package.swift');

if (existsSync(spm)) {
  const swift = readFileSync(spm, 'utf8');

  for (const plugin of plugins) {
    if (swift.includes(nativeName(plugin))) continue;

    const podspecOnly =
      existsSync(join(ROOT, 'node_modules', plugin)) &&
      !existsSync(join(ROOT, 'node_modules', plugin, 'Package.swift'));

    if (ACCEPTED.has(plugin)) {
      accepted.push(`${plugin} — ${ACCEPTED.get(plugin)}`);
      continue;
    }

    problems.push(
      podspecOnly
        ? `${plugin}: not linked on iOS, and it ships no Package.swift — a CocoaPods-only plugin cannot go into this SPM project`
        : `${plugin}: not linked on iOS. Run npm run sync --workspace mobile (not "cap sync android", which leaves iOS behind)`,
    );
  }
} else {
  notes.push('ios: no Swift package manifest, so nothing to check there');
}

// --- Android: a gradle file with one line per plugin project ----------------

const gradle = join(MOBILE, 'android/capacitor.settings.gradle');

if (existsSync(gradle)) {
  const settings = readFileSync(gradle, 'utf8');

  for (const plugin of plugins) {
    if (!settings.includes(plugin)) {
      problems.push(`${plugin}: not linked on Android. Run npm run sync --workspace mobile`);
    }
  }
} else {
  notes.push('android: no capacitor.settings.gradle, so nothing to check there');
}

for (const note of notes) console.log(`native plugins: ${note}`);

for (const known of accepted) console.log(`native plugins: known gap — ${known}`);

if (problems.length > 0) {
  console.error('\nnative plugins: installed but not linked\n');

  for (const problem of problems) console.error(`  ${problem}`);

  console.error(
    '\nA plugin that is not linked does not fail. The feature that needed it\n' +
      'simply does nothing, on a device, in somebody’s hand.\n'
  );

  process.exit(1);
}

console.log(
  `native plugins: ${plugins.length} installed, ${plugins.length - accepted.length} linked on both platforms`,
);
