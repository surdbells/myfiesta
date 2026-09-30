import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { OrganizerEventDetail } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../core/api';
import { EventWorkspace } from './event-workspace';

/** Stands in for a tab: the door, the orders. */
@Component({ selector: 'app-probe-tab', template: '<p>The door</p>' })
class ProbeTab {}

/**
 * The frame around one event's tabs, for an address that is no event.
 *
 * /events/detty-december-warm-up/door — the slug where the id goes — said
 * "Event not found" and then drew the door beneath it anyway, which asked the
 * server for the passes and the ticket list of an event that does not exist.
 */
describe('EventWorkspace', () => {
  let backend: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'events/:id', component: EventWorkspace, children: [{ path: 'door', component: ProbeTab }] }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  async function open(key: string) {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(`/events/${key}/door`);
    harness.detectChanges();

    const text = () => (harness.routeNativeElement?.textContent ?? '').replace(/\s+/g, ' ');

    return { harness, text };
  }

  it('draws no tab under "Event not found"', async () => {
    const { harness, text } = await open('detty-december-warm-up');

    // Nothing below the header while the server is asked.
    expect(text()).not.toContain('The door');

    backend
      .expectOne('http://api.test/api/organizer/events/detty-december-warm-up')
      .flush({ message: 'Not found.' }, { status: 404, statusText: 'Not Found' });
    harness.detectChanges();

    expect(text()).toContain('Event not found');
    expect(text()).not.toContain('The door');
  });

  it('draws the tab once the event is there', async () => {
    const { harness, text } = await open('ev-1');

    backend.expectOne('http://api.test/api/organizer/events/ev-1').flush({
      id: 'ev-1',
      slug: 'afro-fest',
      title: 'Afro Fest',
      status: 'published',
      starts_at: '2026-11-14T01:00:00Z',
      timezone: 'America/Toronto',
      city: 'Toronto',
      poster_url: null,
      review: { submitted_at: null, approved_at: null, on_submit: null, unchanged_since_approval: true, not_ready: [], rejection: null, history: [] },
    } as unknown as OrganizerEventDetail);
    harness.detectChanges();

    expect(text()).toContain('Afro Fest');
    expect(text()).toContain('The door');
  });

  it('keeps the door out of signal: the server not answering is not "not found"', async () => {
    const { harness, text } = await open('ev-1');

    backend.expectOne('http://api.test/api/organizer/events/ev-1').error(new ProgressEvent('error'), { status: 0 });
    harness.detectChanges();

    expect(text()).toContain('Can’t reach the server');
    expect(text()).toContain('The door');
    harness.fixture.destroy();
  });
});
