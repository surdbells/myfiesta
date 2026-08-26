import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

/**
 * Every `var(--token)` in the apps, checked against the tokens that exist.
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
 * This is the cheap check that makes the next one impossible. It runs on the
 * generated CSS rather than on tokens.json, so it also catches a token that is
 * declared but fails to build.
 */

const ROOT = new URL('../../', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

const SEARCH = [
  'packages/ui',
  'apps/web/src',
  'apps/organizer-web/src',
];

const EXTENSIONS = ['.ts', '.css', '.scss', '.html'];

/** Every token the build actually emitted. */
function defined() {
  const css = readFileSync(join(ROOT, 'packages/tokens/dist/tokens.css'), 'utf8');

  return new Set([...css.matchAll(/^\s+(--[a-z0-9-]+):/gm)].map((m) => m[1]));
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

for (const dir of SEARCH) {
  for (const path of files(join(ROOT, dir))) {
    const source = readFileSync(path, 'utf8');

    for (const [, token] of source.matchAll(/var\((--[a-z0-9-]+)/g)) {
      if (known.has(token)) continue;

      const where = missing.get(token) ?? new Set();
      where.add(relative(ROOT, path));
      missing.set(token, where);
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

console.log(`tokens: every var() in the apps resolves (${known.size} defined)`);
