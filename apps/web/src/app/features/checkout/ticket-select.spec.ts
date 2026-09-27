import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DOCUMENT, RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { EventDetail, TicketType } from '../../core/api.types';
import { CheckoutStore } from '../../core/checkout-store';
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

const GENERAL: TicketType = {
  id: 'general',
  name: 'General',
  description: null,
  price: { amount: 2500, currency: 'CAD' },
  admits: 1,
  max_per_order: null,
  status: 'on_sale',
  sales_start_at: null,
  sales_end_at: null,
  sold_out: false,
  opens_after: null,
  waiting: false,
};

const AFRO = {
  slug: 'afro',
  title: 'Afro Night',
  city: 'Toronto',
  timezone: 'America/Toronto',
  starts_at: '2026-10-03T22:00:00Z',
  ends_at: null,
  currency: 'CAD',
  from_price: { amount: 2500, currency: 'CAD' },
  poster_url: null,
  og_image_url: null,
  description: null,
  description_text: null,
  venue: null,
  organizer: { name: 'Lagos Nights', slug: 'lagos-nights', description: null, is_verified: true, logo_url: null },
  ticket_types: [GENERAL],
  add_ons: [],
  questions: [],
  gallery: [],
} as unknown as EventDetail;

/**
 * A promoter's ref, on a link straight to the ticket page.
 *
 * The site's event page keeps `?ref=` for the order. The phone app sends a
 * promoter's buyers from its own event screen straight to
 * /{slug}/tickets?ref=…, never past that page, and the ref used to stop
 * there: the promoter was not credited, their discount was not quoted and
 * their presale tiers stayed shut. The ticket page now keeps it the same way.
 */
describe("TicketSelect, a promoter's ref", () => {
  let http: HttpTestingController;
  let store: CheckoutStore;

  async function open(url: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url, TicketSelect);

    http.expectOne('https://api.myfiesta.test/api/events/afro').flush({ data: AFRO });
    harness.detectChanges();

    return harness;
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: ':slug/tickets', component: TicketSelect }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: { status: 200 } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
    store = TestBed.inject(CheckoutStore);
  });

  afterEach(() => {
    http.verify();
    sessionStorage.clear();
  });

  it('keeps the ref from the address and quotes with it', async () => {
    const harness = await open('/afro/tickets?ref=dj-kay');

    expect(store.ref()).toBe('dj-kay');

    (harness.routeDebugElement!.componentInstance as TicketSelect).adjust(GENERAL, 1);

    const quote = http.expectOne('https://api.myfiesta.test/api/events/afro/quote');
    expect(quote.request.body).toEqual(expect.objectContaining({ ref: 'dj-kay' }));
    quote.flush(null);
  });

  it('keeps the ref the event page kept when this address carries none', async () => {
    store.ref.set('spring-mail');

    await open('/afro/tickets');

    expect(store.ref()).toBe('spring-mail');
  });
});
