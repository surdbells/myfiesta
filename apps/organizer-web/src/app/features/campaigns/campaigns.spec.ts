import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting, type TestRequest } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { Campaign } from '../../core/api.types';
import { allowDialogs, asked, forgetDialogs } from '../../core/confirm-testing';
import { Campaigns } from './campaigns';

/**
 * The campaigns list, as the server reads it: several statuses, lists and
 * events at once from a link, and the order chosen from its one sort control.
 */
describe('Campaigns', () => {
  let backend: HttpTestingController;

  const page = {
    data: [],
    meta: { total: 0, per_page: 20, current_page: 1, last_page: 1, next: null },
    audiences: [{ value: 'followers', label: 'Followers', needs_event: false }],
    statuses: [],
    written_about: [],
    events: [],
  };

  beforeAll(allowDialogs);

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'campaigns', component: Campaigns }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    backend.match((r) => r.url.endsWith('/campaigns/audience')).forEach((r) => r.flush({ all: 0, reachable: 0 }));
    backend.verify();
    forgetDialogs();
  });

  const listRequest = (): TestRequest => backend.expectOne((r) => r.url === 'http://api.test/api/organizer/campaigns');

  async function open(url: string) {
    const harness = await RouterTestingHarness.create();
    const screen = await harness.navigateByUrl(url, Campaigns);

    const settle = async () => {
      harness.detectChanges();
      await harness.fixture.whenStable();
      harness.detectChanges();
    };

    await settle();
    const first = listRequest();
    first.flush(page);
    await settle();
    for (const views of backend.match((r) => r.url === 'http://api.test/api/organizer/saved-views')) views.flush({ data: [] });

    return { screen, settle, first };
  }

  it('asks for several statuses and lists from a link', async () => {
    const { first } = await open('/campaigns?status=sent&status=scheduled&audience=followers&q=back');

    expect(first.request.params.getAll('status[]')).toEqual(['sent', 'scheduled']);
    expect(first.request.params.getAll('audience[]')).toEqual(['followers']);
    expect(first.request.params.get('q')).toBe('back');
    expect(first.request.params.get('sort')).toBe('created');
    expect(first.request.params.get('dir')).toBe('desc');
  });

  it('asks again in the order chosen', async () => {
    const { screen, settle } = await open('/campaigns');

    screen.sortBy('subject:asc');
    await settle();

    const request = listRequest();
    expect(request.request.params.get('sort')).toBe('subject');
    expect(request.request.params.get('dir')).toBe('asc');
    request.flush(page);
  });

  /*
   * "Sent 28 Sep, 00:48" and "Goes Thu 1 Oct, 00:48" beside a console that
   * writes "Sep 29, 6:15 p.m." everywhere else.
   */
  it('writes when it went or goes as the rest of the console does', async () => {
    const { screen } = await open('/campaigns');

    // "Sep 28, 6:15 p.m." and "Thu, Oct 1, 12:48 a.m.", whatever the zone the spec runs in.
    expect(screen.timeOf('2026-09-28T22:15:00Z')).toMatch(/^[A-Z][a-z]{2} \d{1,2}, \d{1,2}:\d{2}\s[ap]\.m\.$/);
    expect(screen.timeOf('2026-10-01T04:48:00Z', true)).toMatch(/^[A-Z][a-z]{2}, [A-Z][a-z]{2} \d{1,2}, \d{1,2}:\d{2}\s[ap]\.m\.$/);
    expect(screen.dayOf('2026-09-28T12:00:00Z')).toMatch(/^[A-Z][a-z]{2} \d{1,2}$/);
  });

  it('does not offer "Cancel" beside "Cancel it" when asking to cancel one', async () => {
    const { screen, settle } = await open('/campaigns');

    screen.cancelling.set({ id: 'c-1', subject: 'We are back' } as Campaign);
    await settle();

    expect(asked()?.title).toBe('Cancel “We are back”?');
    expect(asked()?.buttons).toEqual(['Keep it', 'Cancel it']);
  });
});
