#!/usr/bin/env node
/**
 * Checks the pairings the design system promises are readable.
 *
 * A token pair like "text on surface" is a claim about contrast, and the claim
 * is easy to break: retuning a ramp step for one screen quietly drops another
 * below the threshold, and nobody notices because whoever changed it was
 * looking at the screen that got better.
 *
 * Run against the generated CSS rather than tokens.json, so it checks what
 * actually ships including any hand-written hex in the theme layer.
 *
 * WCAG 2.2 AA: 4.5:1 for body text, 3:1 for large text (>=18.66px bold or
 * >=24px) and for the boundary of a control. Buttons here are 14px semibold,
 * which is body text — the common mistake is to score them as large.
 */

import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/** Foreground, background, minimum, and what it is. */
const PAIRS = [
  ['text', 'surface', 4.5, 'body text on a page'],
  ['text', 'surface-raised', 4.5, 'body text on a card'],
  ['text', 'surface-inset', 4.5, 'body text on an inset'],
  ['text-muted', 'surface', 4.5, 'secondary text'],
  ['text-muted', 'surface-raised', 4.5, 'secondary text on a card'],
  ['text-subtle', 'surface', 4.5, 'hints and placeholders'],
  // Hints are written on every ground, not only a page's: a footer heading on
  // the sunken ground, "Sold out" on a card, a select's empty value on an
  // inset. Checked on white alone, the light one passed at 4.62:1 while it
  // read 4.33:1 in the footer and 3.99:1 in a select.
  ['text-subtle', 'surface-sunken', 4.5, 'hints on the page ground'],
  ['text-subtle', 'surface-raised', 4.5, 'hints on a card'],
  ['text-subtle', 'surface-inset', 4.5, "a field's empty value"],
  ['on-primary', 'primary', 4.5, 'a primary button label'],
  ['on-primary', 'primary-hover', 4.5, 'a primary button, hovered'],
  ['on-accent', 'accent', 4.5, 'an accent button label'],
  ['primary-text', 'surface', 4.5, 'brand-coloured text'],
  ['primary-text', 'surface-raised', 4.5, 'brand-coloured text on a card'],
  ['primary-soft-text', 'primary-soft', 4.5, 'text on a soft brand fill'],
  ['success', 'surface', 4.5, 'a success message'],
  ['warning', 'surface', 4.5, 'a warning message'],
  ['danger', 'surface', 4.5, 'an error message'],
  ['info', 'surface', 4.5, 'an informational message'],
  ['text-inverse', 'danger', 4.5, 'a destructive button label'],
  ['on-scarce', 'scarce', 4.5, 'an "Almost sold out" badge'],
  ['on-sold', 'sold', 4.5, 'a "Sold out" badge'],
  ['scarce', 'surface-raised', 4.5, '"Only 4 left" written on a card'],
  // Boundaries, not text.
  ['field-border', 'surface', 3, 'an input outline'],
  ['field-border', 'surface-raised', 3, 'an input outline on a card'],
  ['primary', 'surface', 3, 'the focus ring'],
];

const channel = (v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);

function luminance(hex) {
  const raw = hex.replace('#', '');
  const full = raw.length === 3 ? [...raw].map((c) => c + c).join('') : raw;
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16) / 255);

  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrast(a, b) {
  const [l1, l2] = [luminance(a), luminance(b)];

  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

/** Pull `--name: #value;` pairs out of one CSS block. */
function declarationsIn(block) {
  const found = new Map();

  for (const [, name, value] of block.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{3,8})\s*;/g)) {
    found.set(name, value);
  }

  return found;
}

const css = await readFile(join(here, 'dist', 'tokens.css'), 'utf8');

// The light palette lives in the first :root block; dark is redefined in the
// explicit [data-theme="dark"] block, which carries the complete set.
const rootBlock = /:root\s*\{([\s\S]*?)\}/.exec(css)?.[1] ?? '';
const darkBlock = /:root\[data-theme="dark"\]\s*\{([\s\S]*?)\}/.exec(css)?.[1] ?? '';

const light = declarationsIn(rootBlock);
const dark = new Map([...light, ...declarationsIn(darkBlock)]);

let failed = 0;

for (const [theme, tokens] of [
  ['light', light],
  ['dark', dark],
]) {
  for (const [fg, bg, min, what] of PAIRS) {
    const a = tokens.get(fg);
    const b = tokens.get(bg);

    if (!a || !b) {
      console.error(`  MISSING  ${theme}: ${fg} on ${bg} — token not defined`);
      failed++;
      continue;
    }

    const ratio = contrast(a, b);

    if (ratio < min) {
      console.error(
        `  FAIL     ${theme}: ${what} — ${fg} (${a}) on ${bg} (${b}) ` +
          `is ${ratio.toFixed(2)}:1, needs ${min}:1`
      );
      failed++;
    }
  }
}

if (failed > 0) {
  console.error(`\ncontrast: ${failed} pairing(s) below the threshold`);
  process.exit(1);
}

console.log(`contrast: ${PAIRS.length * 2} pairings pass in both themes`);
