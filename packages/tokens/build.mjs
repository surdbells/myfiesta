#!/usr/bin/env node
/**
 * Emits the design tokens for both client languages from one source.
 *
 *   tokens.json  ->  dist/tokens.css   (CSS custom properties, for Angular)
 *                ->  dist/tokens.dart  (constants, for Flutter)
 *
 * Flutter cannot consume a TypeScript package, so a shared library is not an
 * option — this generator is what keeps the web and mobile surfaces looking
 * like one product. Run `npm run build` in this package; CI checks that the
 * committed output matches, so a token change cannot land half-applied.
 *
 * Two layers come out of it. Primitives (`color`, `space`, `font`…) are emitted
 * once. The semantic layer (`theme.light` / `theme.dark`) is emitted three
 * times, which is what makes a page readable in every theme state a viewer can
 * be in:
 *
 *   :root                                  the light palette, always defined
 *   @media (prefers-color-scheme: dark)    guarded so an explicit light choice
 *     :root:not([data-theme="light"])      still beats a dark OS setting
 *   :root[data-theme="dark"]               so an explicit dark choice wins too
 *
 * The middle one is the case that gets forgotten: most people never touch a
 * theme toggle, so their document carries no data-theme at all and only the
 * media query separates light from dark.
 */

import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const NOTICE = 'GENERATED FROM tokens.json — DO NOT EDIT. Run: npm run build';
const BANNER = `/* ${NOTICE} */`;

/** Flatten nested token objects into `prefix-key` pairs, skipping $-metadata. */
function flatten(node, path = [], out = []) {
  for (const [key, value] of Object.entries(node)) {
    if (key.startsWith('$')) continue;
    const next = [...path, key];
    if (value !== null && typeof value === 'object') {
      flatten(value, next, out);
    } else {
      out.push([next.join('-'), value]);
    }
  }
  return out;
}

/**
 * Resolve `{color.brand.500}` against the primitives.
 *
 * Emitting `var(--color-brand-500)` instead would work in CSS and not in Dart,
 * and would make the two outputs disagree about what a token *is*. Resolving
 * here keeps one answer.
 */
function resolve(value, primitives) {
  if (typeof value !== 'string') return value;

  const match = /^\{([^}]+)\}$/.exec(value.trim());
  if (!match) return value;

  const key = match[1].replace(/\./g, '-');
  const found = primitives.get(key);

  if (found === undefined) {
    throw new Error(`tokens: ${value} does not point at anything`);
  }

  return found;
}

const isColor = (name) => name.startsWith('color-');
const isBareNumber = (name) =>
  name.startsWith('space-') || name.startsWith('radius-') || name.startsWith('font-size-');

/** `color-brand-500` -> `colorBrand500` */
function camel(name) {
  return name.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase());
}

/** `#08911f` -> `0xFF08911F`; anything not a plain hex stays a string in Dart. */
function dartColor(hex) {
  const raw = hex.replace('#', '');
  const full = raw.length === 3 ? [...raw].map((c) => c + c).join('') : raw;
  return `0xFF${full.toUpperCase()}`;
}

const isHex = (value) => typeof value === 'string' && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value);

function declarations(tokens, indent = '  ') {
  return tokens
    .map(([name, value]) => {
      const suffix = isBareNumber(name) && typeof value === 'number' ? 'px' : '';
      return `${indent}--${name}: ${value}${suffix};`;
    })
    .join('\n');
}

function toCss(primitives, light, dark) {
  return [
    BANNER,
    '',
    ':root {',
    declarations(primitives),
    '',
    '  /* Semantic layer. Components use only these. */',
    declarations(light),
    '}',
    '',
    '/* No data-theme attribute is set for the default "system" setting, so this',
    '   media query is the only thing separating light from dark for most',
    '   people. Guarded so an explicit light choice still wins. */',
    '@media (prefers-color-scheme: dark) {',
    '  :root:not([data-theme="light"]) {',
    declarations(dark, '    '),
    '  }',
    '}',
    '',
    '/* An explicit dark choice, which must also beat a light OS setting. */',
    ':root[data-theme="dark"] {',
    declarations(dark),
    '}',
    '',
  ].join('\n');
}

function dartFields(tokens, { colors }) {
  return tokens.map(([name, value]) => {
    const id = camel(name);

    if (colors && isHex(value)) {
      return `  static const Color ${id} = Color(${dartColor(String(value))});`;
    }
    if (typeof value === 'number') {
      return `  static const double ${id} = ${value.toFixed(1)};`;
    }
    return `  static const String ${id} = ${JSON.stringify(String(value))};`;
  });
}

function toDart(primitives, light, dark) {
  return [
    `// ${NOTICE}`,
    '',
    "import 'dart:ui' show Color;",
    '',
    '/// Raw values. Prefer [LightTheme] / [DarkTheme] in widgets — a widget that',
    '/// reaches for a primitive directly is one that will be unreadable in one',
    '/// of the two themes.',
    'abstract final class Tokens {',
    ...dartFields(primitives, { colors: true }),
    '}',
    '',
    '/// The semantic layer, light.',
    'abstract final class LightTheme {',
    ...dartFields(light, { colors: true }),
    '}',
    '',
    '/// The semantic layer, dark.',
    'abstract final class DarkTheme {',
    ...dartFields(dark, { colors: true }),
    '}',
    '',
  ].join('\n');
}

const source = JSON.parse(await readFile(join(here, 'tokens.json'), 'utf8'));

const { theme, ...rest } = source;
const primitives = flatten(rest);
const lookup = new Map(primitives);

const resolveAll = (node) =>
  flatten(node).map(([name, value]) => [name, resolve(value, lookup)]);

const light = resolveAll(theme.light);
const dark = resolveAll(theme.dark);

// Every semantic name must exist in both themes. A token defined only in light
// falls back to whatever the primitive layer left behind when the page renders
// dark — which is the classic one-theme's-text-on-the-other-theme's-ground bug,
// and it is invisible to anyone developing in light mode.
const lightNames = new Set(light.map(([name]) => name));
const darkNames = new Set(dark.map(([name]) => name));
const missing = [
  ...[...lightNames].filter((n) => !darkNames.has(n)).map((n) => `${n} (missing from dark)`),
  ...[...darkNames].filter((n) => !lightNames.has(n)).map((n) => `${n} (missing from light)`),
];

if (missing.length > 0) {
  console.error(`tokens: themes disagree —\n  ${missing.join('\n  ')}`);
  process.exit(1);
}

const dart = toDart(primitives, light, dark);

await mkdir(join(here, 'dist'), { recursive: true });
await writeFile(join(here, 'dist', 'tokens.css'), toCss(primitives, light, dark));
await writeFile(join(here, 'dist', 'tokens.dart'), dart);

// Flutter cannot resolve a file outside its own package, so the Dart output is
// written into the app as well. Committed, and checked by CI — a token change
// that reaches the web but not mobile is exactly the drift this package exists
// to prevent.
const mobileDesign = join(here, '..', '..', 'apps', 'mobile', 'lib', 'design');
await mkdir(mobileDesign, { recursive: true });
await writeFile(join(mobileDesign, 'tokens.dart'), dart);

console.log(
  `tokens: ${primitives.length} primitives + ${light.length} semantic (light and dark) ` +
    '-> dist/tokens.css, dist/tokens.dart, apps/mobile/lib/design/tokens.dart'
);
