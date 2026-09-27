import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DOCUMENT, RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { EventDetail } from './event-detail';

/**
 * An event link that leads nowhere.
 *
 * It used to render "Event not found" and answer 200 — a soft 404, which a
 * search engine keeps under the event's name long after it was unpublished.
 * These are rendered as the server renders them, with a response to set.
 */
describe('EventDetail, when there is no event', () => {
  let response: ResponseInit;
  let http: HttpTestingController;

  async function open(slug: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(`/${slug}`, EventDetail);

    return harness;
  }

  beforeEach(() => {
    response = { status: 200 };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: ':slug', component: EventDetail }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: response },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('answers 404 and keeps the page out of search when the API says there is none', async () => {
    const harness = await open('gone');

    http
      .expectOne('https://api.myfiesta.test/api/events/gone')
      .flush({ message: 'Event not found.' }, { status: 404, statusText: 'Not Found' });
    harness.detectChanges();

    expect(response.status).toBe(404);
    expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
    // Still the friendly page, with somewhere to go next.
    expect(harness.routeNativeElement?.textContent).toContain('Event not found');
    expect(harness.routeNativeElement?.querySelector('a[href="/events"]')).not.toBeNull();
  });

  it('answers 503, not 404, when the API did not answer', async () => {
    // Every event page dropping out of search because the API had a bad
    // minute is the failure a blanket 404 would cause.
    const harness = await open('afrobeats-rooftop');

    http
      .expectOne('https://api.myfiesta.test/api/events/afrobeats-rooftop')
      .flush({ message: 'Server Error' }, { status: 500, statusText: 'Server Error' });
    harness.detectChanges();

    expect(response.status).toBe(503);
    expect(harness.routeNativeElement?.textContent).toContain('could not be loaded');
    expect(harness.routeNativeElement?.textContent).not.toContain('Event not found');
    expect(TestBed.inject(DOCUMENT).title).toBe('Event unavailable — myFiesta');
  });
});
