import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { describe, expect, it } from 'vitest';
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

  it('fades nothing when every tab fits', () => {
    const { nav, size } = render();

    size(600, 900, 0);

    expect(nav.classList).not.toContain('tabs--more-after');
    expect(nav.classList).not.toContain('tabs--more-before');
  });
});
