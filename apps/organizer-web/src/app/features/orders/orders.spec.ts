import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting, type TestRequest } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { OrganizationOrder } from '../../core/api.types';
import { Orders } from './orders';

/**
 * The orders screen, as the server receives it.
 *
 * What is pinned is what the reader cannot see go wrong: several statuses
 * sent as several values rather than the last one; the days sent in the
 * reader's own zone (a yyyy-mm-dd alone is a Greenwich day, which for a
 * Toronto organizer is a "Today" that ends in the early evening); the export
 * asking for the same list in the same order; the ticked rows exported alone;
 * and a link carrying the filters opening the list it describes.
 */
describe('Orders', () => {
  let backend: HttpTestingController;

  const order = (id: string): OrganizationOrder => ({
    id,
    reference: `REF${id}`,
    buyer_name: `Buyer ${id}`,
    buyer_email: `${id}@example.com`,
    status: 'paid',
    paid_at: '2026-09-20T20:00:00Z',
    tickets_count: 1,
    event: { id: 'ev-1', title: 'Afro Fest' },
    total: { amount: 5000, currency: 'CAD' },
    refunded: { amount: 0, currency: 'CAD' },
    signals: [],
  });

  const page = (rows: OrganizationOrder[] = []) => ({
    data: rows,
    meta: { total: rows.length, per_page: 25, current_page: 1, last_page: 1, summary: null },
  });

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'orders', component: Orders }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  async function open(url = '/orders', rows: OrganizationOrder[] = []) {
    const harness = await RouterTestingHarness.create();
    const screen = await harness.navigateByUrl(url, Orders);

    backend.expectOne('http://api.test/api/organizer/events/options').flush({ data: [] });

    const settle = async () => {
      harness.detectChanges();
      await harness.fixture.whenStable();
      harness.detectChanges();
    };

    await settle();
    // The saved views load beside the list; none here.
    for (const views of backend.match((r) => r.url === 'http://api.test/api/organizer/saved-views')) views.flush({ data: [] });
    const first = listRequest();
    first.flush(page(rows));
    await settle();

    return { screen, harness, settle, first };
  }

  const listRequest = (): TestRequest => backend.expectOne((r) => r.url === 'http://api.test/api/organizer/orders');

  const zone = () => Intl.DateTimeFormat().resolvedOptions().timeZone;

  it('opens the list a link describes, sending several values of one filter as several', async () => {
    const { first } = await open('/orders?status=refunded&status=pending&q=ada&sort=total&dir=asc');

    const params = first.request.params;
    expect(params.getAll('status[]')).toEqual(['refunded', 'pending']);
    expect(params.get('q')).toBe('ada');
    expect(params.get('sort')).toBe('total');
    expect(params.get('dir')).toBe('asc');
    expect(params.has('timezone')).toBe(false);
  });

  it('asks for a range in the reader’s own zone', async () => {
    const { screen, settle } = await open();

    screen.setRange({ from: '2026-02-07', to: '2026-02-07' });
    await settle();

    const params = listRequest().request.params;
    expect(params.get('from')).toBe('2026-02-07');
    expect(params.get('to')).toBe('2026-02-07');
    expect(params.get('timezone')).toBe(zone());
  });

  it('asks for amounts in the smallest unit', async () => {
    const { screen, settle } = await open();

    screen.setAmounts({ min: 5000, max: null });
    await settle();

    const params = listRequest().request.params;
    expect(params.get('min_total')).toBe('5000');
    expect(params.has('max_total')).toBe(false);
  });

  it('exports the same list, in the same zone and the same order', async () => {
    const { screen, settle } = await open('/orders?sort=total&dir=desc');

    screen.setRange({ from: '2026-02-01', to: null });
    await settle();
    listRequest().flush(page());

    screen.export();

    const request = backend.expectOne((r) => r.url === 'http://api.test/api/organizer/orders/export');
    expect(request.request.params.get('from')).toBe('2026-02-01');
    expect(request.request.params.get('timezone')).toBe(zone());
    expect(request.request.params.get('sort')).toBe('total');
    expect(request.request.params.has('page')).toBe(false);
    // Answered with a refusal so no file is saved: only the question matters here.
    request.flush(null, { status: 503, statusText: 'Service Unavailable' });
  });

  it('exports only the ticked orders when asked to', async () => {
    const { screen } = await open('/orders', [order('a'), order('b'), order('c')]);

    screen.selection.toggle('a');
    screen.selection.toggle('c');
    screen.export(true);

    const request = backend.expectOne((r) => r.url === 'http://api.test/api/organizer/orders/export');
    expect(request.request.params.getAll('ids[]')).toEqual(['a', 'c']);
    request.flush(null, { status: 503, statusText: 'Service Unavailable' });
  });

  it('forgets the ticks when the filter changes what the rows are', async () => {
    const { screen, settle } = await open('/orders', [order('a'), order('b')]);

    screen.selection.toggle('a');
    expect(screen.selection.count()).toBe(1);

    screen.list.set('status', ['refunded']);
    await settle();
    listRequest().flush(page());

    expect(screen.selection.count()).toBe(0);
  });

  it('says a refusal is a refusal, and a failure can be tried again', async () => {
    const { screen, settle } = await open();

    screen.list.set('q', 'x');
    await settle();
    listRequest().flush(null, { status: 500, statusText: 'Server Error' });
    await settle();
    expect(screen.failed()).toBe(true);

    screen.load();
    await settle();
    listRequest().flush(page([order('a')]));
    await settle();
    expect(screen.failed()).toBe(false);
    expect(screen.orders().length).toBe(1);
  });

  /*
   * The page's takings are money received; a payment still confirming is on
   * the page but not in them, and the figure says it left it out.
   */
  it('says which orders on the page its takings leave out', async () => {
    const { screen, harness, settle } = await open();
    const cad = (amount: number) => ({ amount, currency: 'CAD' as const });

    screen.list.set('q', 'ada');
    await settle();
    listRequest().flush({
      data: [order('a'), { ...order('b'), status: 'pending', paid_at: null, tickets_count: 0 }],
      meta: {
        total: 2,
        per_page: 25,
        current_page: 1,
        last_page: 1,
        summary: { gross: cad(5000), refunded: cad(0), net: cad(5000), confirming: 1 },
      },
    });
    await settle();

    const text = (harness.routeNativeElement?.textContent ?? '').replace(/\s+/g, ' ');
    expect(text, text).toContain('Not counting 1 order still confirming');
  });
});
