import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { DOCUMENT, RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { EventList, byPrice } from './event-list';

const API = 'https://api.myfiesta.test';

const facets = {
  categories: [{ category: 'Comedy', slug: 'comedy', events: 2, cover_url: null }],
  cities: [{ city: 'Lagos', country: 'NG', slug: 'lagos', events: 4, cover_url: 'https://cdn.test/lagos.jpg' }],
};

const card = {
  slug: 'lagos-laughs',
  title: 'Lagos Laughs',
  starts_at: '2026-10-03T18:00:00Z',
  timezone: 'Africa/Lagos',
  city: 'Lagos',
  country: 'NG',
  currency: 'NGN',
  category: 'Comedy',
  poster_url: null,
  organizer: { name: 'Punchline', slug: 'punchline' },
  from_price: { amount: 750000, currency: 'NGN' },
  is_sold_out: false,
  availability: { state: 'almost_sold_out', left: null },
  waitlist: false,
};

/**
 * The listing, and the pages each category and city has of their own.
 *
 * Rendered as the server renders them, with a response to set: a category
 * that does not exist has to answer 404, not a soft "nothing here" at 200
 * that a search engine keeps indexed.
 */
describe('EventList', () => {
  let response: ResponseInit;
  let http: HttpTestingController;

  beforeEach(() => {
    response = { status: 200 };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'events', component: EventList },
          { path: 'events/category/:category', component: EventList, data: { collection: 'category' } },
          { path: 'events/city/:city', component: EventList, data: { collection: 'city' } },
        ]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: API },
        { provide: RESPONSE_INIT, useValue: response },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  async function open(url: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url, EventList);
    http.expectOne(`${API}/api/discover/facets`).flush(facets);

    return harness;
  }

  const listing = (): TestRequest => http.expectOne((request) => request.url === `${API}/api/events`);

  it('passes when, availability and dates to the server as it has them', async () => {
    await open('/events?when=weekend&availability=almost_sold_out&city=Lagos');

    const request = listing();
    expect(request.request.params.get('when')).toBe('weekend');
    expect(request.request.params.get('availability')).toBe('almost_sold_out');
    expect(request.request.params.get('city')).toBe('Lagos');
    request.flush({ data: [card], meta: { next_cursor: null } });
  });

  it('reads a range of days, and the old front page links', async () => {
    await open('/events?date=date&on=2026-10-03');

    const request = listing();
    expect(request.request.params.get('date_from')).toBe('2026-10-03');
    expect(request.request.params.get('date_to')).toBe('2026-10-03');
    expect(request.request.params.has('when')).toBe(false);
    request.flush({ data: [], meta: { next_cursor: null } });
  });

  it('gives a city its own title, words and picture, and lists what is on there', async () => {
    const harness = await open('/events/city/lagos');

    http.expectOne(`${API}/api/discover/cities/lagos`).flush({ data: facets.cities[0] });
    const request = listing();
    expect(request.request.params.get('city')).toBe('Lagos');
    request.flush({ data: [card], meta: { next_cursor: null } });
    harness.detectChanges();

    const page = harness.routeNativeElement as HTMLElement;
    expect(page.querySelector('h1')?.textContent).toContain('Events in Lagos');
    expect(page.textContent).toContain('Nigeria');
    // The city is the page; offering to change it would leave the page.
    expect(page.querySelector('ui-select[name="city"]')).toBeNull();
    expect(page.querySelector('[data-tone="scarce"]')?.textContent?.trim()).toBe('Almost sold out');

    const document = TestBed.inject(DOCUMENT);
    expect(document.title).toContain('Events in Lagos');
    expect(document.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe('https://myfiesta.ca/events/city/lagos');
    expect(TestBed.inject(Meta).getTag('property="og:image"')?.content).toBe('https://cdn.test/lagos.jpg');

    const structured = JSON.parse(document.querySelector('script[type="application/ld+json"]')?.textContent ?? '{}');
    expect(structured['@type']).toBe('ItemList');
    expect(structured.itemListElement[0].item).toMatchObject({ '@type': 'Event', name: 'Lagos Laughs' });
    expect(structured.itemListElement[0].item.offers.availability).toBe('https://schema.org/LimitedAvailability');
  });

  it('opens a city with nothing coming up on the past nights its words promise', async () => {
    const harness = await open('/events/city/lagos');

    http.expectOne(`${API}/api/discover/cities/lagos`).flush({ data: { ...facets.cities[0], events: 0 } });
    const request = listing();
    expect(request.request.params.get('city')).toBe('Lagos');
    expect(request.request.params.get('when')).toBe('past');
    request.flush({ data: [{ ...card, availability: { state: 'sold_out', left: null }, is_sold_out: true }], meta: { next_cursor: null } });
    harness.detectChanges();

    const page = harness.routeNativeElement as HTMLElement;
    expect(page.querySelector('h1')?.textContent).toContain('Past events in Lagos');
    expect(page.textContent).toContain('Past nights are below');
    expect(page.textContent).toContain('Past event');
  });

  it('keeps an empty city page to its word when somebody asks for what is coming up', async () => {
    const harness = await open('/events/city/lagos?when=upcoming');

    http.expectOne(`${API}/api/discover/cities/lagos`).flush({ data: { ...facets.cities[0], events: 0 } });
    const request = listing();
    expect(request.request.params.has('when')).toBe(false);
    request.flush({ data: [], meta: { next_cursor: null } });
    harness.detectChanges();

    const page = harness.routeNativeElement as HTMLElement;
    expect(page.textContent).toContain('Nothing coming up in Lagos right now');
    expect(page.textContent).not.toContain('Past nights are below');
  });

  it('fixes a category page to its category', async () => {
    const harness = await open('/events/category/comedy');

    http.expectOne(`${API}/api/discover/categories/comedy`).flush({ data: facets.categories[0] });
    const request = listing();
    expect(request.request.params.get('category')).toBe('Comedy');
    request.flush({ data: [card], meta: { next_cursor: null } });
    harness.detectChanges();

    expect((harness.routeNativeElement as HTMLElement).querySelector('h1')?.textContent?.trim()).toBe('Comedy');
    expect(TestBed.inject(DOCUMENT).title).toContain('Comedy events and tickets');
  });

  it('answers 404 for a category that is not one, and lists nothing', async () => {
    const harness = await open('/events/category/nope');

    http.expectOne(`${API}/api/discover/categories/nope`).flush({ message: 'No such category.' }, { status: 404, statusText: 'Not Found' });
    harness.detectChanges();

    expect(response.status).toBe(404);
    expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
    expect((harness.routeNativeElement as HTMLElement).textContent).toContain('Nothing here by that name');
    http.expectNone((request) => request.url === `${API}/api/events`);
  });

  it('answers 503, not 404, when the API did not answer', async () => {
    const harness = await open('/events/city/lagos');

    http.expectOne(`${API}/api/discover/cities/lagos`).flush({}, { status: 500, statusText: 'Server Error' });
    harness.detectChanges();

    expect(response.status).toBe(503);
  });
});

/**
 * Sorting by price across two currencies.
 *
 * The plain comparison of minor units put every Canadian night before every
 * Nigerian one — ₦5,000 is 500000 to $89's 8900 — so "low to high" read
 * $10 … $89, then ₦5,000. Each currency is now sorted on its own.
 */
describe('byPrice', () => {
  const night = (slug: string, from_price: { amount: number; currency: 'CAD' | 'NGN' } | null) =>
    ({ ...card, slug, from_price }) as unknown as Parameters<typeof byPrice>[0][number];

  const listing = [
    night('toronto-89', { amount: 8900, currency: 'CAD' }),
    night('lagos-5000', { amount: 500000, currency: 'NGN' }),
    night('toronto-free', { amount: 0, currency: 'CAD' }),
    night('toronto-10', { amount: 1000, currency: 'CAD' }),
    night('soon', null),
    night('lagos-2000', { amount: 200000, currency: 'NGN' }),
    night('lagos-free', { amount: 0, currency: 'NGN' }),
  ];

  const slugs = (direction: 'asc' | 'desc') => byPrice(listing, direction).map((e) => e.slug);

  it('puts free first, then each currency cheapest first, and nights with no price last', () => {
    expect(slugs('asc')).toEqual([
      'toronto-free',
      'lagos-free',
      'toronto-10',
      'toronto-89',
      'lagos-2000',
      'lagos-5000',
      'soon',
    ]);
  });

  it('turns each currency round for dearest first, with free after them', () => {
    expect(slugs('desc')).toEqual([
      'toronto-89',
      'toronto-10',
      'lagos-5000',
      'lagos-2000',
      'toronto-free',
      'lagos-free',
      'soon',
    ]);
  });

  it('never compares naira with dollars', () => {
    const order = slugs('asc');

    // Every paid Canadian night together, and every paid Nigerian one.
    expect(order.indexOf('toronto-89') + 1).toBe(order.indexOf('lagos-2000'));
  });
});
