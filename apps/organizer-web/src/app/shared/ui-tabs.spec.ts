import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { describe, expect, it, vi } from 'vitest';
import { UiTabs } from '@myfiesta/ui';

/**
 * A row of tabs wider than the page says there is more of it.
 *
 * The event workspace cut its last two tabs off at laptop widths, on a tab
 * boundary, so the row looked complete. The edge with tabs past it now fades.
 */
describe('UiTabs', () => {
  function render() {
    TestBed.configureTestingModule({ providers: [provideRouter([])] });

    const fixture = TestBed.createComponent(UiTabs);
    fixture.componentRef.setInput('tabs', [
      { label: 'Overview', route: ['/'] },
      { label: 'Tickets', route: ['/tickets'] },
      { label: 'Settings', route: ['/settings'] },
    ]);
    fixture.detectChanges();

    const nav = (fixture.nativeElement as HTMLElement).querySelector('nav')!;

    // jsdom lays nothing out; give the row the widths a laptop would.
    const size = (scrollWidth: number, clientWidth: number, scrollLeft: number) => {
      Object.defineProperty(nav, 'scrollWidth', { configurable: true, value: scrollWidth });
      Object.defineProperty(nav, 'clientWidth', { configurable: true, value: clientWidth });
      Object.defineProperty(nav, 'scrollLeft', { configurable: true, value: scrollLeft, writable: true });
      fixture.componentInstance.measure();
      fixture.detectChanges();
    };

    return { nav, size };
  }

  it('fades the edge that has more tabs past it', () => {
    const { nav, size } = render();

    size(900, 600, 0);
    expect(nav.classList).toContain('tabs--more-after');
    expect(nav.classList).not.toContain('tabs--more-before');

    size(900, 600, 150);
    expect(nav.classList).toContain('tabs--more-after');
    expect(nav.classList).toContain('tabs--more-before');

    size(900, 600, 300);
    expect(nav.classList).not.toContain('tabs--more-after');
    expect(nav.classList).toContain('tabs--more-before');
  });

  /*
   * A page loaded straight at a tab's address, on a phone. The row used to
   * look for the current tab after its first render, a moment before the
   * link was marked current, so it found none and "Flyer & gallery" stayed
   * past the right edge until somebody navigated inside the console.
   */
  it('scrolls the current tab into the row on a page loaded at its address', async () => {
    @Component({ template: '' })
    class Blank {}

    TestBed.configureTestingModule({
      providers: [provideRouter([{ path: 'overview', component: Blank }, { path: 'tickets', component: Blank }, { path: 'pictures', component: Blank }])],
    });
    await TestBed.inject(Router).navigateByUrl('/pictures');

    // jsdom lays nothing out: a 343px row, with the last tab 454px past its start.
    const box = (left: number, right: number) => ({ left, right, top: 0, bottom: 40, width: right - left, height: 40, x: left, y: 0 }) as DOMRect;
    const rect = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      if (this.tagName === 'NAV') return box(16, 359);
      return this.textContent?.includes('Flyer') ? box(813, 934) : box(16, 120);
    });

    try {
      const fixture = TestBed.createComponent(UiTabs);
      fixture.componentRef.setInput('tabs', [
        { label: 'Overview', route: ['/overview'] },
        { label: 'Tickets', route: ['/tickets'] },
        { label: 'Flyer & gallery', route: ['/pictures'] },
      ]);

      fixture.detectChanges();
      const row = (fixture.nativeElement as HTMLElement).querySelector('nav')!;
      Object.defineProperty(row, 'scrollLeft', { configurable: true, value: 0, writable: true });

      await fixture.whenStable();
      fixture.detectChanges();

      expect(row.querySelector('a.is-active')?.textContent).toContain('Flyer & gallery');
      // 934 − 359, and 48px of the next tab's worth of room beyond it.
      expect(row.scrollLeft).toBe(934 - 359 + 48);
    } finally {
      rect.mockRestore();
    }
  });

  it('fades nothing when every tab fits', () => {
    const { nav, size } = render();

    size(600, 900, 0);

    expect(nav.classList).not.toContain('tabs--more-after');
    expect(nav.classList).not.toContain('tabs--more-before');
  });
});
