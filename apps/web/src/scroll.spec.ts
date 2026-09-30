import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

/**
 * A row that scrolls sideways draws no scrollbar.
 *
 * The front page's shelves had a thin grey bar under them on Windows, and on
 * a Mac whenever it shows scrollbars, and the posters above them hid theirs
 * with a rule of their own. Every sideways row takes one utility now
 * (scroll-x, from packages/ui/styles/scroll.css), and this fails a template
 * that scrolls sideways without it, or styles a bar back in.
 */

const ROOT = process.cwd();
const styles = readFileSync(resolve(ROOT, 'src/styles.css'), 'utf8');
const home = readFileSync(resolve(ROOT, 'src/app/features/home/home.html'), 'utf8');

function* templates(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) yield* templates(path);
    else if (/\.(html|ts|css)$/.test(entry) && !entry.endsWith('.spec.ts')) yield path;
  }
}

describe('a sideways row', () => {
  it('takes the utility from outside a layer, which is what makes it one', () => {
    expect(styles).toMatch(/^@import '\.\.\/\.\.\/\.\.\/packages\/ui\/styles\/scroll\.css';\r?$/m);
  });

  it("is scroll-x on the front page's shelves and, below 1024px, its posters", () => {
    expect(home).toMatch(/'grid-flow-col [^']*\bscroll-x\b[^']*\bbleed\b/);
    expect(home).toMatch(/class="mosaic [^"]*max-\[1024px\]:scroll-x/);
  });

  it('is scroll-x everywhere, rather than a scrollbar of its own', () => {
    const own: string[] = [];
    const sideways = /overflow-x-(auto|scroll)|\boverflow-(auto|scroll)\b|overflow-x:\s*(auto|scroll)|overflow:\s*(auto|scroll)|scrollbar-width|::-webkit-scrollbar/;

    for (const file of templates(resolve(ROOT, 'src/app'))) {
      if (sideways.test(readFileSync(file, 'utf8'))) own.push(relative(ROOT, file).replaceAll('\\', '/'));
    }

    expect(own).toEqual([]);
  });
});
