import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting, type TestRequest } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { Guest } from '../../core/api.types';
import { EventGuests } from './event-guests';

/**
 * The guest list, as the server reads it: several tiers at once, the sort,
 * and a spreadsheet that is the list on screen — its filters and its order,
 * or only the guests ticked. The export used to ignore every filter, so a
 * "VIPs not arrived yet" list downloaded as everybody.
 */
describe('EventGuests', () => {
  let backend: HttpTestingController;
  const BASE = 'http://api.test/api/organizer/events/ev-1';

  const guest = (id: string): Guest => ({
    id,
    name: `Guest ${id}`,
    email: `${id}@example.com`,
    ticket_type: 'General',
    checked_in: false,
    checked_in_at: null,
    answers: [],
  });

  const page = (rows: Guest[]) => ({
    data: rows,
    meta: { total: rows.length, per_page: 50, current_page: 1, last_page: 1, next: null, checked_in: 0 },
  });

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'events/:id/guests', component: EventGuests }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  const listRequest = (): TestRequest => backend.expectOne((r) => r.url === `${BASE}/guests`);

  async function open(url: string, rows: Guest[] = []) {
    const harness = await RouterTestingHarness.create();
    const screen = await harness.navigateByUrl(url, EventGuests);

    const settle = async () => {
      harness.detectChanges();
      await harness.fixture.whenStable();
      harness.detectChanges();
    };

    await settle();
    backend.expectOne(`${BASE}/ticket-types`).flush({ data: [] });
    for (const views of backend.match((r) => r.url === 'http://api.test/api/organizer/saved-views')) views.flush({ data: [] });
    const first = listRequest();
    first.flush(page(rows));
    await settle();

    return { screen, settle, first };
  }

  it('asks for several tiers and the sort, as the link says', async () => {
    const { first } = await open('/events/ev-1/guests?ticket_type_id=t-1&ticket_type_id=t-2&status=valid&sort=email');

    expect(first.request.params.getAll('ticket_type_id[]')).toEqual(['t-1', 't-2']);
    expect(first.request.params.get('status')).toBe('valid');
    expect(first.request.params.get('sort')).toBe('email');
  });

  it('exports the list as filtered and sorted', async () => {
    const { screen } = await open('/events/ev-1/guests?status=checked_in&q=ada&sort=name&dir=desc');

    screen.exportList();

    const request = backend.expectOne((r) => r.url === `${BASE}/guests/export`);
    expect(request.request.params.get('status')).toBe('checked_in');
    expect(request.request.params.get('q')).toBe('ada');
    expect(request.request.params.get('dir')).toBe('desc');
    expect(request.request.params.has('page')).toBe(false);
    request.flush(null, { status: 503, statusText: 'Service Unavailable' });
  });

  it('exports only the ticked guests when asked to', async () => {
    const { screen } = await open('/events/ev-1/guests', [guest('a'), guest('b')]);

    screen.selection.toggle('b');
    screen.exportList(true);

    const request = backend.expectOne((r) => r.url === `${BASE}/guests/export`);
    expect(request.request.params.getAll('ids[]')).toEqual(['b']);
    request.flush(null, { status: 503, statusText: 'Service Unavailable' });
  });
});
