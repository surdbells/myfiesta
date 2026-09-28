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
import { ConfirmDialog, type ConfirmRequest } from '@myfiesta/ui';

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

/**
 * How much is left, beside each ticket.
 *
 * "Almost sold out", "Only 4 left" and "Sold out" are the API's, counted the
 * way checkout counts. A tier that is gone cannot be chosen, and one that
 * goes while somebody is choosing comes out of the basket when the quote
 * says so — not at the payment step, where it would be refused.
 */
describe('TicketSelect, how much is left', () => {
  let http: HttpTestingController;
  let store: CheckoutStore;

  const FEW: TicketType = { ...GENERAL, id: 'few', name: 'Early bird', availability: { state: 'almost_sold_out', left: 4 } };
  const NEARLY: TicketType = { ...GENERAL, id: 'nearly', name: 'General', availability: { state: 'almost_sold_out', left: null } };
  const GONE: TicketType = { ...GENERAL, id: 'gone', name: 'VIP', sold_out: true, availability: { state: 'sold_out', left: null } };
  const PLENTY: TicketType = { ...GENERAL, id: 'plenty', name: 'Balcony', availability: { state: 'available', left: null } };

  async function open(tiers: TicketType[]): Promise<{ harness: RouterTestingHarness; page: TicketSelect; text: () => string }> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/afro/tickets', TicketSelect);

    http.expectOne('https://api.myfiesta.test/api/events/afro').flush({ data: { ...AFRO, ticket_types: tiers } });
    harness.detectChanges();

    const page = harness.routeDebugElement!.componentInstance as TicketSelect;

    return { harness, page, text: () => (harness.routeNativeElement?.textContent ?? '').replace(/\s+/g, ' ') };
  }

  function tierCard(harness: RouterTestingHarness, name: string): HTMLElement {
    const cards = [...(harness.routeNativeElement?.querySelectorAll<HTMLElement>('.tier') ?? [])];

    return cards.find((card) => card.querySelector('h2')?.textContent?.includes(name))!;
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

  it('names a small count, says "Almost sold out" without one, and nothing when there is plenty', async () => {
    const { harness } = await open([FEW, NEARLY, PLENTY]);

    expect(tierCard(harness, 'Early bird').textContent).toContain('Only 4 left');
    expect(tierCard(harness, 'General').textContent).toContain('Almost sold out');
    expect(tierCard(harness, 'Balcony').querySelector('.availability')).toBeNull();
  });

  it('shows a sold-out tier as sold out, and it cannot be chosen', async () => {
    const { harness, page } = await open([GONE, PLENTY]);
    const card = tierCard(harness, 'VIP');

    expect(card.textContent).toContain('Sold out');
    expect(card.querySelector<HTMLButtonElement>('button[aria-label="One more VIP"]')!.disabled).toBe(true);

    page.adjust(GONE, 1);
    expect(page.quantity('gone')).toBe(0);
  });

  it('offers the waitlist once every tier has gone', async () => {
    const { text } = await open([GONE]);

    expect(text()).toContain('Sold out — join the waitlist');
  });

  it('stops the stepper at what is left', async () => {
    const { harness, page } = await open([FEW]);

    for (let i = 0; i < 6; i++) {
      page.adjust(FEW, 1);
      http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush(null);
    }
    harness.detectChanges();

    expect(page.quantity('few')).toBe(4);
    expect(tierCard(harness, 'Early bird').querySelector<HTMLButtonElement>('button[aria-label="One more Early bird"]')!.disabled).toBe(true);
  });

  it('takes a tier that sold out while somebody chose out of the basket, and says so', async () => {
    const { harness, page, text } = await open([PLENTY, NEARLY]);

    page.adjust(PLENTY, 1);
    http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush({
      availability: [
        { ticket_type_id: 'plenty', state: 'sold_out', left: null },
        { ticket_type_id: 'nearly', state: 'almost_sold_out', left: 3 },
      ],
    });

    // Nothing is left in the basket, so there is nothing to price again.
    http.expectNone('https://api.myfiesta.test/api/events/afro/quote');
    harness.detectChanges();

    expect(store.lines()).toEqual([]);
    expect(text()).toContain('Balcony sold out while you were choosing');
    expect(tierCard(harness, 'Balcony').textContent).toContain('Sold out');
    expect(tierCard(harness, 'General').textContent).toContain('Only 3 left');
  });

  it('brings a line down to what is left, and prices the basket again', async () => {
    const { harness, page, text } = await open([NEARLY]);

    page.adjust(NEARLY, 1);
    http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush(null);
    page.adjust(NEARLY, 1);
    http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush(null);
    page.adjust(NEARLY, 1);
    http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush({
      availability: [{ ticket_type_id: 'nearly', state: 'almost_sold_out', left: 2 }],
    });

    const again = http.expectOne('https://api.myfiesta.test/api/events/afro/quote');
    expect(again.request.body).toEqual(expect.objectContaining({ items: [{ ticket_type_id: 'nearly', quantity: 2 }] }));
    again.flush({ availability: [{ ticket_type_id: 'nearly', state: 'almost_sold_out', left: 2 }] });
    harness.detectChanges();

    expect(page.quantity('nearly')).toBe(2);
    expect(text()).toContain('Only 2 General are left, so your order now has 2.');
  });

  it('reads the same news from a refused quote', async () => {
    const { harness, page } = await open([PLENTY]);

    page.adjust(PLENTY, 1);
    http
      .expectOne('https://api.myfiesta.test/api/events/afro/quote')
      .flush(
        { message: 'Balcony is not currently on sale.', availability: [{ ticket_type_id: 'plenty', state: 'sold_out', left: null }] },
        { status: 422, statusText: 'Unprocessable Content' },
      );
    harness.detectChanges();

    expect(store.lines()).toEqual([]);
    expect(tierCard(harness, 'Balcony').textContent).toContain('Sold out');
  });

  it('lets a buyer choose as many as the organizer allows, past twenty', async () => {
    const BIG: TicketType = { ...PLENTY, id: 'big', name: 'Table', max_per_order: 30 };
    const { harness, page } = await open([BIG]);

    for (let i = 0; i < 31; i++) {
      page.adjust(BIG, 1);
      http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush(null);
    }
    harness.detectChanges();

    expect(page.quantity('big')).toBe(30);
    expect(tierCard(harness, 'Table').querySelector<HTMLButtonElement>('button[aria-label="One more Table"]')!.disabled).toBe(true);
  });

  it('says sales closed, not sold out, and offers no waitlist, when sales ended with places left', async () => {
    const ENDED: TicketType = {
      ...GENERAL,
      id: 'ended',
      name: 'Online',
      sales_end_at: '2020-01-01T00:00:00Z',
      availability: { state: 'closed', left: null },
    };
    const { harness, text } = await open([ENDED]);
    const card = tierCard(harness, 'Online');

    expect(card.textContent).toContain('Sales closed');
    expect(card.textContent).not.toContain('Sold out');
    expect(card.querySelector<HTMLButtonElement>('button[aria-label="One more Online"]')!.disabled).toBe(true);
    expect(text()).not.toContain('join the waitlist');
  });

  it('takes a tier whose sales closed while somebody chose out of the basket, and says so', async () => {
    const { harness, page, text } = await open([PLENTY]);

    page.adjust(PLENTY, 1);
    http
      .expectOne('https://api.myfiesta.test/api/events/afro/quote')
      .flush(
        { message: 'Sales for Balcony have ended.', availability: [{ ticket_type_id: 'plenty', state: 'closed', left: null }] },
        { status: 422, statusText: 'Unprocessable Content' },
      );
    harness.detectChanges();

    expect(store.lines()).toEqual([]);
    expect(text()).toContain('Sales for Balcony closed while you were choosing');
    expect(tierCard(harness, 'Balcony').textContent).toContain('Sales closed');
    expect(tierCard(harness, 'Balcony').textContent).not.toContain('Sold out');
  });

  it('prices only the newest basket when two quotes are in flight', async () => {
    const { page } = await open([PLENTY]);

    page.adjust(PLENTY, 1);
    const older = http.expectOne('https://api.myfiesta.test/api/events/afro/quote');
    page.adjust(PLENTY, 1);
    const newer = http.expectOne('https://api.myfiesta.test/api/events/afro/quote');

    // The first answer is for a basket that no longer exists; it is not waited for.
    expect(older.cancelled).toBe(true);
    newer.flush({ subtotal: { amount: 5000, currency: 'CAD' } });

    expect(page.quote()?.subtotal).toEqual({ amount: 5000, currency: 'CAD' });
  });

  it('shows no subtotal for a basket the quote has just changed', async () => {
    const { page } = await open([NEARLY]);

    page.adjust(NEARLY, 1);
    http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush(null);
    page.adjust(NEARLY, 1);
    http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush({
      subtotal: { amount: 5000, currency: 'CAD' },
      availability: [{ ticket_type_id: 'nearly', state: 'almost_sold_out', left: 1 }],
    });

    // That subtotal was for two; the basket now holds one, priced again.
    expect(page.quote()).toBeNull();
    http.expectOne('https://api.myfiesta.test/api/events/afro/quote').flush({ subtotal: { amount: 2500, currency: 'CAD' } });
    expect(page.quote()?.subtotal).toEqual({ amount: 2500, currency: 'CAD' });
  });
});

/**
 * Joining the waitlist asks first, with the address in the question.
 *
 * There is no account behind a waitlist entry: the address is the only way
 * to reach whoever joined, and saying no sends nothing.
 */
describe('TicketSelect, joining the waitlist', () => {
  let http: HttpTestingController;
  let asked: ConfirmRequest[];
  let yes: boolean;

  async function open(): Promise<TicketSelect> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/afro/tickets', TicketSelect);

    http.expectOne('https://api.myfiesta.test/api/events/afro').flush({ data: { ...AFRO, ticket_types: [{ ...GENERAL, sold_out: true }] } });
    harness.detectChanges();

    return harness.routeDebugElement!.componentInstance as TicketSelect;
  }

  beforeEach(() => {
    asked = [];
    yes = false;

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: ':slug/tickets', component: TicketSelect }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: { status: 200 } },
        // The person reading the question; the dialog itself is the kit's, and tested there.
        { provide: ConfirmDialog, useValue: { confirm: async (request: ConfirmRequest) => (asked.push(request), yes) } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    sessionStorage.clear();
  });

  it('says the address back, and joins nothing when the answer is no', async () => {
    const page = await open();
    page.waitEmail.set('ada@example.test');
    page.waitQuantity.set('2');

    await page.joinWaitlist();

    expect(asked[0].title).toBe('Join the waitlist for Afro Night?');
    expect(asked[0].body).toBe('We email ada@example.test if 2 places come up. Nothing is held or charged.');
    expect(asked[0].confirmLabel).toBe('Join the waitlist');
    http.expectNone('https://api.myfiesta.test/api/events/afro/waitlist');
    expect(page.waitJoining()).toBe(false);
  });

  it('joins once it is confirmed', async () => {
    const page = await open();
    page.waitEmail.set('ada@example.test');
    yes = true;

    await page.joinWaitlist();

    const join = http.expectOne('https://api.myfiesta.test/api/events/afro/waitlist');
    expect(join.request.body).toEqual({ email: 'ada@example.test', name: undefined, quantity: 1 });
    join.flush({ message: "You're on the waitlist." });
    expect(page.waitDone()).toBe("You're on the waitlist.");
  });
});
