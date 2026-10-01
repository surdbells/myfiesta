import { TestBed } from '@angular/core/testing';
import { EventDetail } from '../../../core/api.types';
import { OtherDate } from '../../../core/types/sched';
import { OtherDatesPart } from './other-dates-part';

function render(otherDates: OtherDate[] | null, timezone = 'America/Toronto') {
  const fixture = TestBed.createComponent(OtherDatesPart);
  fixture.componentRef.setInput('event', {
    slug: 'afro-fridays',
    title: 'Afro Fridays',
    timezone,
    other_dates: otherDates,
  } as unknown as EventDetail);
  fixture.detectChanges();

  return fixture.nativeElement as HTMLElement;
}

describe('OtherDatesPart', () => {
  it('draws nothing, and adds no box to the rail, for a night that does not repeat', () => {
    const host = render(null);

    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });

  it('draws nothing when no other date is on sale', () => {
    const host = render([]);

    expect(host.children.length).toBe(0);
  });

  it('lists each other date in the venue zone, linking to its own page', () => {
    const host = render([
      // 9pm in Toronto on Friday 9 October, 01:00 UTC on the Saturday.
      { slug: 'afro-fridays-2026-10-09', starts_at: '2026-10-10T01:00:00Z', availability: null },
      { slug: 'afro-fridays-2026-10-16', starts_at: '2026-10-17T01:00:00Z', availability: null },
    ]);

    expect(host.querySelector('h2')?.textContent).toContain('More dates');

    const links = Array.from(host.querySelectorAll<HTMLAnchorElement>('a.date-link'));
    expect(links.map((a) => a.getAttribute('href'))).toEqual(['/afro-fridays-2026-10-09', '/afro-fridays-2026-10-16']);

    // The venue's Friday, not the UTC Saturday, and the zone named.
    expect(links[0].textContent).toContain('Friday');
    expect(links[0].textContent).toContain('October 9');
    expect(links[0].textContent).toMatch(/9:00\s?p\.?m\.?/i);
    expect(links[0].textContent).toMatch(/EDT/);
    expect(links[0].getAttribute('aria-label')).toContain('Afro Fridays');
  });

  it('says which dates are selling out or sold out, and nothing for the rest', () => {
    const host = render([
      { slug: 'a', starts_at: '2026-10-10T01:00:00Z', availability: { state: 'sold_out', left: null } },
      { slug: 'b', starts_at: '2026-10-17T01:00:00Z', availability: { state: 'almost_sold_out', left: 4 } },
      { slug: 'c', starts_at: '2026-10-24T01:00:00Z', availability: { state: 'available', left: null } },
    ]);

    const links = Array.from(host.querySelectorAll<HTMLAnchorElement>('a.date-link'));
    expect(links[0].querySelector('[data-tone="sold"]')?.textContent).toContain('Sold out');
    // "Almost sold out", not the count: a list of dates is a list of cards.
    expect(links[1].querySelector('[data-tone="scarce"]')?.textContent).toContain('Almost sold out');
    expect(links[2].querySelector('.availability')).toBeNull();
  });
});
