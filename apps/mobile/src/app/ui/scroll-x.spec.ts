import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { beforeAll, describe, expect, it } from 'vitest';
import { MfCarousel } from './carousel';
import { MfChips } from './chips';

/**
 * A row that scrolls sideways draws no scrollbar.
 *
 * The chips over a list, the carousels and the intro's pages each hid their
 * own bar, in rules of their own, and the next sideways row would have had to
 * remember to. They share one class now (scroll-x, in styles.css), and this
 * fails a component that scrolls sideways without it — or a carousel whose
 * cards a keyboard cannot reach, now that there is no bar to drag.
 */

const ROOT = process.cwd();
const styles = readFileSync(resolve(ROOT, 'src/styles.css'), 'utf8');

function* sources(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) yield* sources(path);
    else if (/\.(html|ts|css)$/.test(entry) && !entry.endsWith('.spec.ts')) yield path;
  }
}

/** A rule's declarations, as styles.css writes them. */
const rule = (selector: string) => new RegExp(`^${selector.replace(/[.:-]/g, '\\$&')} \\{([^}]*)\\}`, 'm').exec(styles)?.[1] ?? '';

describe('a sideways row', () => {
  it('scrolls, and hides its bar in every engine the app runs in', () => {
    expect(rule('.scroll-x')).toContain('overflow-x: auto');
    expect(rule('.scroll-x')).toContain('scrollbar-width: none');
    expect(rule('.scroll-x::-webkit-scrollbar')).toContain('display: none');
  });

  it('holds what is positioned in it, rather than letting it widen the screen', () => {
    // A label only a screen reader hears is absolutely positioned. Placed
    // against the page instead of the row, it sits past the row's edge and
    // lets the whole screen be dragged sideways; the console's tables did.
    expect(rule('.scroll-x')).toContain('position: relative');
  });

  it('is scroll-x everywhere, rather than a rule of its own', () => {
    const own: string[] = [];

    for (const file of sources(resolve(ROOT, 'src/app'))) {
      const source = readFileSync(file, 'utf8');

      if (/overflow-x:\s*(auto|scroll)|::-webkit-scrollbar|scrollbar-width/.test(source)) {
        own.push(relative(ROOT, file).replaceAll('\\', '/'));
      }
    }

    expect(own).toEqual([]);
  });
});

@Component({
  imports: [MfChips],
  template: `<mf-chips ariaLabel="Status" [options]="options" [(value)]="value" />`,
})
class ChipsHost {
  readonly options = [
    { value: '', label: 'All' },
    { value: 'paid', label: 'Paid' },
  ];
  readonly value = signal('');
}

@Component({
  imports: [MfCarousel],
  template: `
    <mf-carousel ariaLabel="Featured events" [count]="count()">
      @for (card of cards(); track card) {
        <article>{{ card }}</article>
      }
    </mf-carousel>
  `,
})
class CarouselHost {
  readonly count = signal(3);
  readonly cards = signal(['One', 'Two', 'Three']);
}

describe('MfChips', () => {
  it('is a sideways row of buttons, which a keyboard already moves along', () => {
    const fixture = TestBed.createComponent(ChipsHost);
    fixture.detectChanges();

    const row = (fixture.nativeElement as HTMLElement).querySelector('[role="group"]')!;

    expect(row.classList).toContain('scroll-x');
    expect(row.hasAttribute('tabindex')).toBe(false);
    expect(row.querySelectorAll('button').length).toBe(2);
  });
});

describe('MfCarousel', () => {
  // jsdom scrolls nothing, and has no scrollTo for the carousel to go back
  // to its start with.
  beforeAll(() => {
    HTMLElement.prototype.scrollTo ??= () => undefined;
  });

  it('takes a tab stop while it has cards past the first, so the arrow keys scroll it', () => {
    const fixture = TestBed.createComponent(CarouselHost);
    fixture.detectChanges();

    const track = (fixture.nativeElement as HTMLElement).querySelector('[role="group"]')!;

    expect(track.classList).toContain('scroll-x');
    expect(track.getAttribute('tabindex')).toBe('0');
    expect(track.getAttribute('aria-label')).toBe('Featured events');
  });

  it('takes none with one card, which has nowhere to scroll', () => {
    const fixture = TestBed.createComponent(CarouselHost);
    fixture.componentInstance.count.set(1);
    fixture.componentInstance.cards.set(['One']);
    fixture.detectChanges();

    const track = (fixture.nativeElement as HTMLElement).querySelector('[role="group"]')!;

    expect(track.hasAttribute('tabindex')).toBe(false);
  });
});
