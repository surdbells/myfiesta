import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting, type TestRequest } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import { Orders } from './orders';

/**
 * The orders screen's date filter, as the server receives it.
 *
 * The picker counts days on the reader's calendar. A yyyy-mm-dd sent on its
 * own is read by the server as a day in Greenwich, which for a Toronto
 * organizer is a "Today" that ends in the early evening. So the zone travels
 * with the days, to the list and to the spreadsheet alike.
 */
describe('Orders', () => {
  let backend: HttpTestingController;

  const empty = { data: [], meta: { total: 0, per_page: 25, current_page: 1, last_page: 1, summary: null } };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  function render() {
    const page = TestBed.createComponent(Orders).componentInstance;
    backend.expectOne('http://api.test/api/organizer/events/options').flush({ data: [] });

    return page;
  }

  /** The list is asked for a quarter of a second after the last change. */
  async function listRequest(): Promise<TestRequest> {
    await new Promise((resolve) => setTimeout(resolve, 300));

    const request = backend.expectOne((r) => r.url === 'http://api.test/api/organizer/orders');
    request.flush(empty);

    return request;
  }

  const zone = () => Intl.DateTimeFormat().resolvedOptions().timeZone;

  it('asks for a range in the reader’s own zone', async () => {
    const page = render();
    await listRequest();

    page.range.set({ from: '2026-02-07', to: '2026-02-07' });
    page.refine();

    const params = (await listRequest()).request.params;
    expect(params.get('from')).toBe('2026-02-07');
    expect(params.get('to')).toBe('2026-02-07');
    expect(params.get('timezone')).toBe(zone());
  });

  it('exports the same days in the same zone', async () => {
    const page = render();
    await listRequest();

    page.range.set({ from: '2026-02-01', to: null });
    page.export();

    const request = backend.expectOne((r) => r.url === 'http://api.test/api/organizer/orders/export');
    expect(request.request.params.get('from')).toBe('2026-02-01');
    expect(request.request.params.get('timezone')).toBe(zone());
    // Answered with a refusal so no file is saved: only the question matters here.
    request.flush(null, { status: 503, statusText: 'Service Unavailable' });
  });

  it('names no zone when no days are chosen', async () => {
    render();

    expect((await listRequest()).request.params.has('timezone')).toBe(false);
  });
});
