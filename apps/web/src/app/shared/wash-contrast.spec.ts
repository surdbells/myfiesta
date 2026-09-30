import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Text written over a picture somebody else chose.
 *
 * The contrast check in packages/tokens reasons about token pairs, and none
 * of these is one: each is a colour over an organizer's poster, blended by a
 * wash or a scrim, or over a tint. They were measured from screenshots at
 * 1.9–4.1:1 and looked fine to whoever wrote them, on the poster they had.
 * These work the sums from what ships — the tokens, the wash, the classes —
 * for the worst poster there is, in both themes.
 */

const read = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8');

type Rgb = [number, number, number];

const channel = (v: number) => {
  const c = v / 255;

  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};
const luminance = ([r, g, b]: Rgb) => 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
const contrast = (a: Rgb, b: Rgb) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

  return (hi + 0.05) / (lo + 0.05);
};
/** `over` laid on `under` at `alpha`, as a browser composites: in sRGB. */
const over = (under: Rgb, top: Rgb, alpha: number): Rgb => under.map((v, i) => v * (1 - alpha) + top[i] * alpha) as Rgb;
const hex = (value: string): Rgb => [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16)) as Rgb;

/** Every corner of the colour cube, and a grid between: the worst poster is one of them. */
const POSTERS: Rgb[] = [];
for (let r = 0; r <= 255; r += 51) for (let g = 0; g <= 255; g += 51) for (let b = 0; b <= 255; b += 51) POSTERS.push([r, g, b]);

const tokensCss = read('../../packages/tokens/dist/tokens.css');

/** The tokens of one theme, as tokens.css ships them. */
function theme(name: 'light' | 'dark'): Record<string, Rgb> {
  const root = /:root\s*\{([\s\S]*?)\}/.exec(tokensCss)![1];
  const dark = /:root\[data-theme="dark"\]\s*\{([\s\S]*?)\}/.exec(tokensCss)![1];
  const found: Record<string, Rgb> = {};

  for (const block of name === 'light' ? [root] : [root, dark]) {
    for (const [, token, value] of block.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{6})\s*;/g)) found[token] = hex(value);
  }

  return found;
}

const THEMES = { light: theme('light'), dark: theme('dark') };

describe('text over a poster', () => {
  it('keeps the page text colour at 4.5:1 over the top of a calm wash, on any poster', () => {
    const base = read('../../packages/ui/styles/base.css');
    const poster = Number(/\.backdrop::before\s*\{[^}]*opacity:\s*([\d.]+)/.exec(base)![1]);
    const calm = Number(/\.backdrop\.backdrop-calm::after\s*\{[^}]*?var\(--surface-sunken\)\s*(\d+)%/.exec(base)![1]) / 100;

    for (const [name, tokens] of Object.entries(THEMES)) {
      const ground = tokens['surface-sunken'];
      const worst = Math.min(...POSTERS.map((p) => contrast(tokens['text'], over(over(ground, p, poster), ground, calm))));

      expect(worst, `${name}: --text on the wash`).toBeGreaterThanOrEqual(4.5);
    }
  });

  it('keeps the category chip on the event poster readable on a white poster with no scrim under it', () => {
    const page = read('src/app/features/events/event-detail.html');
    const chip = /class="category-chip[^"]*bg-\[rgba\((\d+),(\d+),(\d+),([\d.]+)\)\]/.exec(page)!;
    const fill: Rgb = [Number(chip[1]), Number(chip[2]), Number(chip[3])];

    expect(contrast([255, 255, 255], over([255, 255, 255], fill, Number(chip[4])))).toBeGreaterThanOrEqual(4.5);
  });

  it('writes the unfinished-page notice at 4.5:1 on its own warning tint', () => {
    const css = read('src/app/features/info/info.css');
    const note = /\.note\s*\{[^}]*?\bcolor:\s*var\(--([\w-]+)\)[^}]*?background-color:\s*color-mix\(in srgb, var\(--([\w-]+)\) (\d+)%, transparent\)/.exec(css)!;
    const strong = /\.note strong\s*\{[^}]*color:\s*var\(--([\w-]+)\)/.exec(css)!;

    for (const [name, tokens] of Object.entries(THEMES)) {
      const tint = over(tokens['surface-sunken'], tokens[note[2]], Number(note[3]) / 100);

      expect(contrast(tokens[note[1]], tint), `${name}: the notice`).toBeGreaterThanOrEqual(4.5);
      expect(contrast(tokens[strong[1]], tint), `${name}: its first words`).toBeGreaterThanOrEqual(4.5);
    }
  });
});
