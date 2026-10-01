import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it } from 'vitest';
import type { OtherDate } from '@myfiesta/api-types';
import type { EventPage } from '../../core/discovery';
import { MfOtherDates } from './other-dates';

/**
 * "More dates" on a repeating night's screen: its other dates on sale, in the
 * venue's zone, each opening its own screen and saying how much is left the
 * way a card does. Nothing at all for a night that does not repeat.
 */
describe('MfOtherDates', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter([])] });
  });

  function draw(otherDates: OtherDate[] | null): HTMLElement {
    const fixture = TestBed.createComponent(MfOtherDates);
    fixture.componentRef.setInput('event', {
      slug: 'afro-fridays',
      title: 'Afro Fridays',
      timezone: 'America/Vancouver',
      other_dates: otherDates,
    } as unknown as EventPage);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('takes no room for a night that does not repeat, or has no other date on sale', () => {
    expect(draw(null).children.length).toBe(0);
    expect(draw([]).children.length).toBe(0);
  });

  it('lists each date in the venue zone, linking to its own screen', () => {
    const screen = draw([
      // 9pm in Vancouver on Friday 9 October is 04:00 UTC on the Saturday.
      { slug: 'afro-fridays-2026-10-09', starts_at: '2026-10-10T04:00:00Z', availability: null },
      { slug: 'afro-fridays-2026-10-16', starts_at: '2026-10-17T04:00:00Z', availability: { state: 'sold_out', left: null } },
    ]);

    expect(screen.textContent).toContain('More dates');

    const rows = [...screen.querySelectorAll<HTMLAnchorElement>('a.row')];
    expect(rows.map((row) => row.getAttribute('href'))).toEqual(['/e/afro-fridays-2026-10-09', '/e/afro-fridays-2026-10-16']);
    expect(rows[0].textContent).toContain('Fri');
    expect(rows[0].textContent).toMatch(/9:00\s?p\.?m\.?/i);
    expect(rows[0].querySelector('[data-tone]')).toBeNull();
    expect(rows[1].querySelector('[data-tone="sold"]')?.textContent).toContain('Sold out');
  });
});
