import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DOCUMENT, RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { TicketSelect } from './ticket-select';

/**
 * The ticket page for an event that is not there — at /{slug}/tickets, and
 * inside somebody else's site at /embed/{slug}.
 *
 * It used to render "Event not found" and answer 200: a soft 404, which a
 * search engine keeps under the event's name long after it was unpublished.
 * It said the same when the API had only failed to answer. It now answers as
 * the event page does. Rendered as the server renders it, with a response to
 * set.
 */
describe('TicketSelect, when there is no event', () => {
  let response: ResponseInit;
  let http: HttpTestingController;

  async function open(url: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url, TicketSelect);

    // Inside a frame the page counts a look; that is not what is under test.
    for (const view of http.match((request) => request.url.endsWith('/views'))) view.flush({});

    return harness;
  }

  beforeEach(() => {
    response = { status: 200 };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'embed/:slug', component: TicketSelect },
          { path: ':slug/tickets', component: TicketSelect },
        ]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: response },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    sessionStorage.clear();
  });

  for (const url of ['/gone/tickets', '/embed/gone']) {
    it(`answers 404 and keeps the page out of search when the API says there is none (${url})`, async () => {
      const harness = await open(url);

      http
        .expectOne('https://api.myfiesta.test/api/events/gone')
        .flush({ message: 'Event not found.' }, { status: 404, statusText: 'Not Found' });
      harness.detectChanges();

      expect(response.status).toBe(404);
      expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
      expect(TestBed.inject(DOCUMENT).title).toBe('Event not found — myFiesta');
      expect(harness.routeNativeElement?.textContent).toContain('Event not found');
    });

    it(`answers 503, not 404, when the API did not answer (${url})`, async () => {
      const harness = await open(url);

      http
        .expectOne('https://api.myfiesta.test/api/events/gone')
        .flush({ message: 'Server Error' }, { status: 500, statusText: 'Server Error' });
      harness.detectChanges();

      expect(response.status).toBe(503);
      expect(harness.routeNativeElement?.textContent).toContain('could not be loaded');
      expect(harness.routeNativeElement?.textContent).not.toContain('Event not found');
    });
  }
});
