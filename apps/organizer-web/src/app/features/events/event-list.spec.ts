import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting, type TestRequest } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { OrganizerEvent, OrganizerEventPage } from '../../core/api.types';
import { EventList } from './event-list';

/**
 * The organizer's events, read for what needs doing today.
 *
 * Pinned: the tab, filters and sort travel to the server as the link says;
 * choosing a tab goes back to that tab's own order; clearing filters keeps
 * the tab; and the figures read the way somebody says them — momentum only
 * when there was a week before to compare with, sell-through only where the
 * room has a size, and a lull flagged only for a night still on sale.
 */
describe('EventList', () => {
  let backend: HttpTestingController;

  const event = (overrides: Partial<OrganizerEvent> = {}): OrganizerEvent => ({
    id: 'ev-1',
    slug: 'afro-fest',
    title: 'Afro Fest',
    kind: 'ticketed',
    status: 'published',
    starts_at: new Date(Date.now() + 10 * 86_400_000).toISOString(),
    timezone: 'America/Toronto',
    city: 'Toronto',
    currency: 'CAD',
    tickets_issued: 90,
    checked_in: 0,
    orders: 40,
    capacity: 100,
    revenue: { amount: 450_000, currency: 'CAD' },
    views: 800,
    last_sale_at: new Date(Date.now() - 2 * 3_600_000).toISOString(),
    poster_url: null,
    trend: { days: [1, 0, 2, 0, 0, 1, 1, 3, 2, 0, 4, 1, 2, 2], this_week: 14, last_week: 5, momentum: 1.8 },
    ...overrides,
  });

  const page = (rows: OrganizerEvent[]): OrganizerEventPage => ({
    data: rows,
    meta: { total: rows.length, per_page: 30, current_page: 1, last_page: 1, next: null },
    summary: {
      events: rows.length,
      upcoming: rows.length,
      tickets_issued: 90,
      checked_in: 0,
      orders: 40,
      sell_through: 0.9,
      revenue: [{ amount: 450_000, currency: 'CAD' }],
      stalled: 0,
      nearly_sold_out: 1,
    },
    cities: ['Lagos', 'Toronto'],
  });

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'events', component: EventList }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  const listRequest = (): TestRequest => backend.expectOne((r) => r.url === 'http://api.test/api/organizer/events');

  async function open(url = '/events', rows: OrganizerEvent[] = [event()]) {
    const harness = await RouterTestingHarness.create();
    const screen = await harness.navigateByUrl(url, EventList);

    const settle = async () => {
      harness.detectChanges();
      await harness.fixture.whenStable();
      harness.detectChanges();
    };

    await settle();
    const first = listRequest();
    first.flush(page(rows));
    await settle();
    for (const views of backend.match((r) => r.url === 'http://api.test/api/organizer/saved-views')) views.flush({ data: [] });

    return { screen, settle, first, element: harness.routeNativeElement as HTMLElement };
  }

  it('asks for the tab, the filters and the sort the link describes', async () => {
    const { first } = await open('/events?when=upcoming&status=published&status=draft&city=Lagos&sort=sell_through&dir=desc&q=afro');

    const params = first.request.params;
    expect(params.get('when')).toBe('upcoming');
    expect(params.getAll('status[]')).toEqual(['published', 'draft']);
    expect(params.getAll('city[]')).toEqual(['Lagos']);
    expect(params.get('sort')).toBe('sell_through');
    expect(params.get('dir')).toBe('desc');
    expect(params.get('q')).toBe('afro');
  });

  it('goes back to a tab’s own order when the tab changes, and keeps the tab when filters clear', async () => {
    const { screen, settle } = await open('/events?sort=revenue&dir=desc&status=draft');

    screen.setWhen('past');
    await settle();

    let params = listRequest().request.params;
    expect(params.get('when')).toBe('past');
    expect(params.has('sort')).toBe(false);
    backend.match(() => true).forEach((r) => r.flush(page([])));

    screen.clearFilters();
    await settle();

    params = listRequest().request.params;
    expect(params.get('when')).toBe('past');
    expect(params.has('status[]')).toBe(false);
  });

  it('shows the strip, the fortnight and this week against the last', async () => {
    const { element } = await open();

    expect(element.textContent).toContain('Nearly full');
    expect(element.querySelector('ui-sparkline svg')?.getAttribute('aria-label')).toBe('19 tickets in the last 14 days, 14 this week');
    expect(element.textContent).toContain('+180%');
  });

  it('reads a night the way somebody would say it', async () => {
    const { screen } = await open();

    // No week before to compare with: no percentage, since "up from nothing" is not one.
    expect(screen.momentum({ days: [], this_week: 3, last_week: 0, momentum: null })).toBeNull();
    expect(screen.momentum({ days: [], this_week: 4, last_week: 5, momentum: -0.2 })).toEqual({ text: '−20%', up: false });

    // An unlimited room has no share of it gone.
    expect(screen.sold(event({ capacity: null }))).toBeNull();
    expect(screen.sold(event({ tickets_issued: 120, capacity: 100 }))).toBe(1);

    // A week without a sale is flagged only for a night still on sale and still to come.
    const quiet = new Date(Date.now() - 8 * 86_400_000).toISOString();
    expect(screen.stalled(event({ last_sale_at: quiet }))).toBe(true);
    expect(screen.stalled(event({ last_sale_at: quiet, status: 'draft' }))).toBe(false);
    expect(screen.stalled(event({ last_sale_at: quiet, starts_at: new Date(Date.now() - 86_400_000).toISOString() }))).toBe(false);
  });

  it('remembers the layout on this browser', async () => {
    const { screen } = await open();

    expect(screen.layout()).toBe('table');
    screen.setLayout('cards');
    expect(localStorage.getItem('myfiesta.list.events.layout')).toBe('cards');
  });
});
