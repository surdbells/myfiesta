import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

/**
 * One frame for every page.
 *
 * The site was a centred column — 1240px on the front page and the listings,
 * 1120px on the event page and checkout — written out in each template with
 * its own px-6, so the header's edge and a page's edge lined up only where
 * two numbers had been kept the same, and a large screen was a third grey
 * margin. Every page now sits in one frame as wide as the window, less a
 * gutter a side (styles.css). This fails a template that goes back to a page
 * width of its own, and a shelf that bleeds by a fixed 24px rather than by
 * the gutter it sits in.
 */

const ROOT = process.cwd();
const styles = readFileSync(resolve(ROOT, 'src/styles.css'), 'utf8');

function* templates(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) yield* templates(path);
    else if (/\.(html|ts)$/.test(entry) && !entry.endsWith('.spec.ts')) yield path;
  }
}

/** A utility's declarations, as styles.css writes them. */
const utility = (name: string) => new RegExp(`@utility ${name.replace('*', '\\*')} \\{([^}]*)\\}`).exec(styles)?.[1] ?? '';

describe('the page frame', () => {
  it('has one gutter: 5% of the window, and never under 16px', () => {
    expect(styles).toMatch(/--page-gutter:\s*max\(1rem,\s*5vw\);/);
  });

  it('pads the window with the gutter and caps nothing', () => {
    expect(utility('frame')).toContain('padding-inline: var(--page-gutter)');
    expect(utility('frame')).not.toMatch(/max-width/);
  });

  it('centres a measure inside the gutter rather than instead of it', () => {
    expect(utility('frame-*')).toMatch(/padding-inline:\s*max\(var\(--page-gutter\),/);
  });

  it('bleeds a sideways row by exactly the gutter, and snaps back to it', () => {
    const bleed = utility('bleed');

    expect(bleed).toContain('margin-inline: calc(-1 * var(--page-gutter))');
    expect(bleed).toContain('padding-inline: var(--page-gutter)');
    expect(bleed).toContain('scroll-padding-inline: var(--page-gutter)');
  });

  it('is the frame every page uses, rather than a column of its own', () => {
    const own: string[] = [];

    for (const file of templates(resolve(ROOT, 'src/app'))) {
      const name = relative(ROOT, file).replaceAll('\\', '/');
      const source = readFileSync(file, 'utf8');

      // A page-wide column: a centred box wider than the widest measure the
      // site keeps (720px). A card or a paragraph capped narrower is a measure.
      for (const [, classes] of source.matchAll(/class="([^"]*)"|'([^']*mx-auto[^']*)'/g)) {
        if (!classes || !/(?<![\w-])mx-auto(?![\w-])/.test(classes)) continue;

        for (const [cap, px, rem, named] of classes.matchAll(/max-w-(?:\[(\d+)px\]|\[(\d+(?:\.\d+)?)rem\]|((?:[3-7]xl)|screen-\w+))/g)) {
          if ((px && Number(px) > 720) || (rem && Number(rem) * 16 > 720) || named) own.push(`${name}: ${cap}`);
        }
      }

      // A row that bleeds by a fixed amount rather than by the gutter.
      for (const [trick] of source.matchAll(/(?<![\w-])-mx-(?:\d+|\[[^\]]+\])(?![\w-])|scroll-px-(?:\d+|\[[^\]]+\])/g)) {
        own.push(`${name}: ${trick}`);
      }
    }

    expect(own).toEqual([]);
  });
});
