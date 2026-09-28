#!/usr/bin/env node
/**
 * Emits the design tokens for both client languages from one source.
 *
 *   tokens.json  ->  dist/tokens.css     (CSS custom properties)
 *                ->  dist/tailwind.css   (the same values, as a Tailwind theme)
 *
 * One source for every client. Both web apps and the phone app read the
 * generated CSS, so a colour changed here changes everywhere or nowhere. A
 * Dart copy used to be emitted beside it for the Flutter app; the phone app is
 * Angular in a Capacitor shell now and reads the same stylesheet the web does,
 * which is one generator and one answer fewer to keep in step.
 *
 * Run `npm run build` in this package; CI checks that the committed output
 * matches, so a token change cannot land half-applied.
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
 * Emitting `var(--color-brand-500)` instead would leave the Tailwind theme
 * holding a reference rather than a value, and the two outputs would disagree
 * about what a token *is*. Resolving here keeps one answer.
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
    '',
    '  /* What the browser draws itself — scrollbars, form controls, the page',
    '     behind an overscroll — follows the palette. Without it those stayed',
    '     light in dark mode: a white scrollbar under every dark shelf. */',
    '  color-scheme: light;',
    '}',
    '',
    '/* No data-theme attribute is set for the default "system" setting, so this',
    '   media query is the only thing separating light from dark for most',
    '   people. Guarded so an explicit light choice still wins. */',
    '@media (prefers-color-scheme: dark) {',
    '  :root:not([data-theme="light"]) {',
    declarations(dark, '    '),
    '    color-scheme: dark;',
    '  }',
    '}',
    '',
    '/* An explicit dark choice, which must also beat a light OS setting. */',
    ':root[data-theme="dark"] {',
    declarations(dark),
    '  color-scheme: dark;',
    '}',
    '',
  ].join('\n');
}

/**
 * The Tailwind theme, generated rather than written.
 *
 * Tailwind is a consumer of the token system, not a second one: bg-primary
 * and var(--primary) must be the same colour forever, so the theme file is
 * emitted here from the same source as everything else.
 *
 * Two blocks with different jobs. Primitives and scales are plain @theme with
 * literal values — they do not change between light and dark. The semantic
 * colours are @theme inline referencing the runtime custom properties, so a
 * utility like bg-surface compiles to var(--surface) and follows the theme at
 * runtime instead of freezing whichever palette was current at build time.
 *
 * The default palette is cleared first. bg-blue-500 compiling is how a colour
 * from outside the system ends up shipped; the primitive ramps and the
 * semantic roles are the whole vocabulary.
 */
function toTailwind(primitives, light) {
  const isColorValue = (v) =>
    typeof v === 'string' && /^(#|rgb|hsl|oklch|color-mix)/.test(v.trim());

  const colorPrimitives = primitives.filter(([name]) => isColor(name));
  const semanticColors = light.filter(([, value]) => isColorValue(value));

  const px = (value) => (typeof value === 'number' ? value + 'px' : value);

  const radius = primitives.filter(([name]) => name.startsWith('radius-'));
  const sizes = primitives.filter(([name]) => name.startsWith('font-size-'));
  const family = new Map(primitives.filter(([name]) => name.startsWith('font-family-')));

  return [
    BANNER,
    '',
    '@theme {',
    '  /* No colour from outside the system compiles. */',
    '  --color-*: initial;',
    '  --color-white: #ffffff;',
    '  --color-black: #000000;',
    ...colorPrimitives.map(([name, value]) => '  --' + name + ': ' + value + ';'),
    '',
    '  --radius-*: initial;',
    ...radius.map(([name, value]) => '  --' + name + ': ' + px(value) + ';'),
    '',
    "  --font-sans: " + family.get('font-family-sans') + ';',
    "  --font-mono: " + family.get('font-family-mono') + ';',
    '',
    '  /* text-sm is font-size-sm, not nearly it. */',
    ...sizes.map(
      ([name, value]) => '  --text-' + name.slice('font-size-'.length) + ': ' + px(value) + ';',
    ),
    '}',
    '',
    '/* Semantic roles, inlined as var() so utilities follow the runtime theme.',
    '   Shadows and the focus ring are not mapped — their token names collide',
    "   with Tailwind's namespaces, and utilities reach them directly as",
    '   shadow-(--shadow-card). */',
    '@theme inline {',
    ...semanticColors.map(([name]) => '  --color-' + name + ': var(--' + name + ');'),
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

await mkdir(join(here, 'dist'), { recursive: true });
await writeFile(join(here, 'dist', 'tokens.css'), toCss(primitives, light, dark));
await writeFile(join(here, 'dist', 'tailwind.css'), toTailwind(primitives, light));
console.log(
  `tokens: ${primitives.length} primitives + ${light.length} semantic (light and dark) ` +
    '-> dist/tokens.css, dist/tailwind.css'
);
