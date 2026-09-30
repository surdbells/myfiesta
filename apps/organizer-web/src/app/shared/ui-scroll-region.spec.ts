import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { UiBreadcrumb, UiScrollRegion, UiTable, UiTabs } from '@myfiesta/ui';

/**
 * Nothing in the console draws a scrollbar under a row that scrolls sideways.
 *
 * A table wider than a phone, the event's tabs, a long breadcrumb, a line of
 * code: each had a grey bar under it on Windows that said nothing the cut-off
 * column did not. They all scroll through one utility now (scroll-x, in
 * packages/ui/styles/scroll.css), and a table's frame through UiScrollRegion,
 * which gives a keyboard back what the bar gave a mouse.
 */

const ROOT = process.cwd();
const KIT = resolve(ROOT, '../../packages/ui');
const utility = readFileSync(resolve(KIT, 'styles/scroll.css'), 'utf8');
const styles = readFileSync(resolve(ROOT, 'src/styles.css'), 'utf8');

function* sources(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) yield* sources(path);
    else if (/\.(html|ts|css)$/.test(entry) && !entry.endsWith('.spec.ts')) yield path;
  }
}

describe('scroll-x', () => {
  it('scrolls sideways and hides the bar in every engine', () => {
    const body = /@utility scroll-x \{([\s\S]*)\}/.exec(utility)?.[1] ?? '';

    expect(body).toContain('overflow-x: auto;');
    expect(body).toContain('scrollbar-width: none;');
    expect(body).toMatch(/&::-webkit-scrollbar \{\s*display: none;\s*\}/);
    // The ring inside the row, where a card that clips the row cannot cut it.
    expect(body).toMatch(/&:focus-visible \{\s*outline-offset: -2px;\s*\}/);
  });

  it('holds what is positioned in it, rather than letting it widen the page', () => {
    const body = /@utility scroll-x \{([\s\S]*)\}/.exec(utility)?.[1] ?? '';

    // The label for a screen reader in a table's last heading is absolutely
    // positioned. Unless the frame is its containing block it is placed
    // against the page, past the frame's edge and the card's, and the page
    // scrolled sideways on /codes and on an event's orders. On the row itself,
    // not on one of its states: everything before the first nested rule.
    expect(body.split('&')[0]).toContain('position: relative;');
  });

  it('is imported outside a layer, which is what makes it a utility', () => {
    expect(styles).toMatch(/^@import '\.\.\/\.\.\/\.\.\/packages\/ui\/styles\/scroll\.css';\r?$/m);
  });

  it('is how everything in the console and the kit scrolls sideways', () => {
    const own: string[] = [];
    const sideways = /overflow-x-(auto|scroll)|\boverflow-(auto|scroll)\b|overflow-x:\s*(auto|scroll)|overflow:\s*(auto|scroll)|scrollbar-width|::-webkit-scrollbar/;

    for (const dir of [resolve(ROOT, 'src/app'), resolve(KIT, 'src')]) {
      for (const file of sources(dir)) {
        if (sideways.test(readFileSync(file, 'utf8'))) own.push(relative(ROOT, file).replaceAll('\\', '/'));
      }
    }

    expect(own).toEqual([]);
  });
});

@Component({
  imports: [UiScrollRegion],
  template: `<div uiScrollRegion="Orders"><table><tbody><tr><td>{{ cell() }}</td></tr></tbody></table></div>`,
})
class Framed {
  readonly cell = signal('A');
}

describe('UiScrollRegion', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  /** jsdom lays nothing out: give the frame and the table the widths a phone would. */
  function laidOut(table: () => number) {
    const resized: (() => void)[] = [];
    vi.stubGlobal(
      'ResizeObserver',
      class {
        constructor(callback: () => void) {
          resized.push(callback);
        }
        observe() {}
        disconnect() {}
      },
    );
    vi.spyOn(Element.prototype, 'scrollWidth', 'get').mockImplementation(function (this: Element) {
      return this.getAttribute('role') === 'region' ? table() : 0;
    });
    vi.spyOn(Element.prototype, 'clientWidth', 'get').mockImplementation(function (this: Element) {
      return this.getAttribute('role') === 'region' ? 343 : 0;
    });

    return { resize: () => resized.forEach((callback) => callback()) };
  }

  async function render() {
    const fixture = TestBed.createComponent(Framed);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return { fixture, frame: (fixture.nativeElement as HTMLElement).querySelector('div')! };
  }

  it('is a named region that scrolls with no bar', async () => {
    laidOut(() => 343);
    const { frame } = await render();

    expect(frame.classList).toContain('scroll-x');
    expect(frame.getAttribute('role')).toBe('region');
    expect(frame.getAttribute('aria-label')).toBe('Orders');
  });

  it('takes a tab stop, for the arrow keys, only while something is past the edge', async () => {
    let table = 720;
    const { resize } = laidOut(() => table);
    const { fixture, frame } = await render();

    expect(frame.getAttribute('tabindex')).toBe('0');

    // The window widened, or a column was hidden: it all fits.
    table = 343;
    resize();
    fixture.detectChanges();

    expect(frame.hasAttribute('tabindex')).toBe(false);
  });

  it('measures again when what is in it changes without changing size', async () => {
    let table = 343;
    laidOut(() => table);
    const { fixture, frame } = await render();

    expect(frame.hasAttribute('tabindex')).toBe(false);

    table = 720;
    fixture.componentInstance.cell.set('A much longer figure');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(frame.getAttribute('tabindex')).toBe('0');
  });

  it('is reachable where it cannot hear about a change of size', async () => {
    vi.stubGlobal('ResizeObserver', undefined);
    const { frame } = await render();

    expect(frame.getAttribute('tabindex')).toBe('0');
  });
});

describe('the kit rows that scroll sideways', () => {
  it("frame a table in a region named by the table's caption", () => {
    const fixture = TestBed.createComponent(UiTable);
    fixture.componentRef.setInput('caption', 'Guests');
    fixture.detectChanges();

    const frame = (fixture.nativeElement as HTMLElement).querySelector('.table__scroll')!;

    expect(frame.classList).toContain('scroll-x');
    expect(frame.getAttribute('role')).toBe('region');
    expect(frame.getAttribute('aria-label')).toBe('Guests');
  });

  it('draw no bar under the tabs or the breadcrumb', () => {
    TestBed.configureTestingModule({ providers: [provideRouter([])] });

    const tabs = TestBed.createComponent(UiTabs);
    tabs.componentRef.setInput('tabs', [{ label: 'Overview', route: ['/'] }]);
    tabs.detectChanges();

    const crumbs = TestBed.createComponent(UiBreadcrumb);
    crumbs.componentRef.setInput('crumbs', [{ label: 'Events', link: ['/events'] }, { label: 'A very long event name' }]);
    crumbs.detectChanges();

    expect((tabs.nativeElement as HTMLElement).querySelector('nav')!.classList).toContain('scroll-x');
    expect((crumbs.nativeElement as HTMLElement).querySelector('ol')!.classList).toContain('scroll-x');
  });
});
