import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

/**
 * Every `var(--token)` in the apps, checked against the tokens that exist —
 * and every colour written as a literal instead of taken from the system.
 *
 * A custom property that was never defined does not fail, warn, or fall back to
 * anything useful. The declaration is simply dropped, and the element renders
 * with whatever it inherited — a border with no colour, a hover state that does
 * not change, text that happens to still be legible on one theme.
 *
 * Which is how six invented names — `--surface-hover`, `--border-subtle`,
 * `--danger-text`, `--focus`, `--motion-medium`, `--motion-ease-out` — got
 * written across a component library and two applications without anything
 * noticing. Three of them turned out to be roles the system genuinely lacked
 * and were added; the other three were misspellings of tokens that already
 * existed.
 *
 * A hex written into a component is the same failure wearing different
 * clothes. It is invisible to the contrast check, which reasons about token
 * pairings — so a colour that reads perfectly in light and at 2.9:1 in dark
 * passes every check this project has and arrives on somebody's phone. That is
 * not hypothetical: it is what a destructive button's label did.
 *
 * This is the cheap check that makes the next one impossible. It runs on the
 * generated CSS rather than on tokens.json, so it also catches a token that is
 * declared but fails to build.
 */

const ROOT = new URL('../../', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

const SEARCH = [
  'packages/ui',
  'apps/web/src',
  'apps/organizer-web/src',
  // The phone app sat outside this list until it had its own contrast bug, in
  // a component the web apps had already got right.
  'apps/mobile/src',
];

const EXTENSIONS = ['.ts', '.css', '.scss', '.html'];

/**
 * Colours that are genuinely not theme colours.
 *
 * Named here rather than tolerated everywhere:
 *
 * - a QR code is true black on true white because that is what a scanner
 *   reads, in any theme, on any phone;
 * - the space behind a live camera is black because it is a camera, not a
 *   surface.
 */
const PAINTS_ITS_OWN = new Set([
  'apps/mobile/src/app/ui/qr.ts',
  'apps/mobile/src/app/features/door/door.ts',
]);

/** Properties where a literal colour is a theme decision made by hand. */
const COLOUR_PROPERTIES =
  /(?:^|[;{\s])(?:color|background|background-color|border|border-color|border-top|border-right|border-bottom|border-left|fill|stroke|outline|outline-color)\s*:[^;{}]*?(#[0-9a-fA-F]{3,8})\b/g;

/**
 * Stylesheets that define custom properties the apps may use.
 *
 * The generated tokens, and the phone app's own sheet — a safe-area inset and
 * a minimum tap target are facts about a phone rather than colours or spacing
 * the web shares, so they live there and are no less defined for it. The web's
 * base layer likewise declares the hooks its classes take from the element
 * they style (the poster behind a .backdrop), with the value they fall back to.
 */
const DEFINITIONS = [
  'packages/tokens/dist/tokens.css',
  'apps/mobile/src/styles.css',
  'packages/ui/styles/base.css',
];

/** Every custom property those sheets actually declare. */
function defined() {
  const names = new Set();

  for (const sheet of DEFINITIONS) {
    const css = readFileSync(join(ROOT, sheet), 'utf8');

    for (const [, name] of css.matchAll(/^\s*(--[a-z0-9-]+)\s*:/gm)) names.add(name);
  }

  return names;
}

function* files(dir) {
  for (const entry of readdirSync(dir)) {
    if (entry === 'node_modules' || entry === 'dist' || entry.startsWith('.')) continue;

    const path = join(dir, entry);

    if (statSync(path).isDirectory()) {
      yield* files(path);
    } else if (EXTENSIONS.some((e) => entry.endsWith(e))) {
      yield path;
    }
  }
}

const known = defined();
const missing = new Map();
const literals = new Map();

for (const dir of SEARCH) {
  for (const path of files(join(ROOT, dir))) {
    const source = readFileSync(path, 'utf8');
    const here = relative(ROOT, path).split('\\').join('/');

    for (const [, token] of source.matchAll(/var\((--[a-z0-9-]+)/g)) {
      if (known.has(token)) continue;

      const where = missing.get(token) ?? new Set();
      where.add(here);
      missing.set(token, where);
    }

    if (PAINTS_ITS_OWN.has(here)) continue;

    for (const [, colour] of source.matchAll(COLOUR_PROPERTIES)) {
      const seen = literals.get(here) ?? new Set();
      seen.add(colour);
      literals.set(here, seen);
    }
  }
}

if (missing.size > 0) {
  console.error(`\ntokens: ${missing.size} referenced but never defined\n`);

  for (const [token, where] of [...missing].sort()) {
    console.error(`  ${token}`);

    for (const file of [...where].sort().slice(0, 6)) {
      console.error(`      ${file}`);
    }

    if (where.size > 6) console.error(`      …and ${where.size - 6} more`);
  }

  console.error(
    '\nAdd the token to tokens.json if the role is real, or use the one that exists.\n'
  );

  process.exit(1);
}

if (literals.size > 0) {
  console.error(`\ntokens: ${literals.size} file(s) paint with a colour of their own\n`);

  for (const [file, colours] of [...literals].sort()) {
    console.error(`  ${file}`);
    console.error(`      ${[...colours].sort().join(', ')}`);
  }

  console.error(
    '\nUse the token for the role. A literal is invisible to the contrast check,\n' +
      'so a colour that reads in light and not in dark passes everything here and\n' +
      'fails on somebody’s phone. If it is genuinely not a theme colour — a QR\n' +
      'code, the space behind a camera — add the file to PAINTS_ITS_OWN and say\n' +
      'why.\n'
  );

  process.exit(1);
}

console.log(
  `tokens: every var() in the apps resolves (${known.size} defined), and nothing paints with a colour of its own`,
);
