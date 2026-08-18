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

const isColor = (name) => name.startsWith('color-');
const isBareNumber = (name) =>
  name.startsWith('space-') ||
  name.startsWith('radius-') ||
  name.startsWith('font-size-');

/** `color-brand-500` -> `colorBrand500` */
function camel(name) {
  return name.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase());
}

/** `#4c5fd7` -> `0xFF4C5FD7` */
function dartColor(hex) {
  const raw = hex.replace('#', '');
  const full = raw.length === 3 ? [...raw].map((c) => c + c).join('') : raw;
  return `0xFF${full.toUpperCase()}`;
}

function toCss(tokens) {
  const lines = tokens.map(([name, value]) => {
    const suffix = isBareNumber(name) && typeof value === 'number' ? 'px' : '';
    return `  --${name}: ${value}${suffix};`;
  });
  return `${BANNER}\n\n:root {\n${lines.join('\n')}\n}\n`;
}

function toDart(tokens) {
  const body = tokens.map(([name, value]) => {
    const id = camel(name);
    if (isColor(name)) {
      return `  static const Color ${id} = Color(${dartColor(String(value))});`;
    }
    if (typeof value === 'number') {
      return `  static const double ${id} = ${value.toFixed(1)};`;
    }
    return `  static const String ${id} = ${JSON.stringify(value)};`;
  });

  return [
    `// ${NOTICE}`,
    '',
    "import 'dart:ui' show Color;",
    '',
    '/// Design tokens shared with the web clients.',
    'abstract final class Tokens {',
    ...body,
    '}',
    '',
  ].join('\n');
}

const source = JSON.parse(await readFile(join(here, 'tokens.json'), 'utf8'));
const tokens = flatten(source);

const dart = toDart(tokens);

await mkdir(join(here, 'dist'), { recursive: true });
await writeFile(join(here, 'dist', 'tokens.css'), toCss(tokens));
await writeFile(join(here, 'dist', 'tokens.dart'), dart);

// Flutter cannot resolve a file outside its own package, so the Dart output is
// written into the app as well. Committed, and checked by CI — a token change
// that reaches the web but not mobile is exactly the drift this package exists
// to prevent.
const mobileDesign = join(here, '..', '..', 'apps', 'mobile', 'lib', 'design');
await mkdir(mobileDesign, { recursive: true });
await writeFile(join(mobileDesign, 'tokens.dart'), dart);

console.log(
  `tokens: wrote ${tokens.length} tokens to dist/tokens.css, dist/tokens.dart, ` +
    'and apps/mobile/lib/design/tokens.dart'
);
