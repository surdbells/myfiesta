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

/** An event as the API sends it, with only what these pages read. */
function detail(overrides: Record<string, unknown> = {}) {
  const tier = (id: string, amount: number) => ({
    id,
    name: id,
    description: null,
    price: { amount, currency: 'CAD' },
    admits: 1,
    max_per_order: null,
    status: 'on_sale',
    sales_start_at: null,
    sales_end_at: null,
    sold_out: false,
    opens_after: null,
    waiting: false,
  });

  return {
    slug: 'qa-free-night',
    title: 'QA Free Night',
    starts_at: '2026-10-03T23:00:00Z',
    ends_at: '2026-10-04T03:00:00Z',
    timezone: 'America/Toronto',
    city: 'Toronto',
    country: 'CA',
    currency: 'CAD',
    category: null,
    poster_url: null,
    og_image_url: null,
    description: null,
    description_text: null,
    gallery: [],
    subdivision: 'ON',
    dress_code: null,
    min_age: null,
    id_required: false,
    venue: { name: 'Harbourfront Loft', address: '8 Queens Quay West' },
    organizer: { name: 'Lagos Nights', slug: 'lagos-nights', logo_url: null, description: null, is_verified: false },
    questions: [],
    add_ons: [],
    calendar: { ics_url: 'https://api.myfiesta.test/x.ics', google_url: 'https://calendar.google.com/x' },
    from_price: { amount: 0, currency: 'CAD' },
    is_sold_out: false,
    availability: { state: 'available', left: null },
    waitlist: false,
    ticket_types: [tier('General', 0)],
    ...overrides,
    ...(overrides['tiers'] ? { ticket_types: (overrides['tiers'] as [string, number][]).map(([id, amount]) => tier(id, amount)) } : {}),
  };
}

/**
 * A free night.
 *
 * Every price here led with "From" or "Starting from", so a free event read
 * "From Free" in its header and "Starting from Free" on its ticket card.
 */
describe('EventDetail, when it is free', () => {
  let http: HttpTestingController;

  async function open(event: ReturnType<typeof detail>): Promise<HTMLElement> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(`/${event.slug}`, EventDetail);
    http.expectOne(`https://api.myfiesta.test/api/events/${event.slug}`).flush({ data: event });
    http.match(() => true).forEach((request) => request.flush({}));
    harness.detectChanges();

    return harness.routeNativeElement as HTMLElement;
  }

  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: ':slug', component: EventDetail }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  it('says "Free", and never "From Free"', async () => {
    const page = await open(detail());

    expect(page.textContent).not.toMatch(/From\s+Free/);
    expect(page.textContent).not.toMatch(/Starting from\s+Free/);
    expect(page.querySelector('.card-price')?.textContent?.trim()).toBe('Free');
    expect(page.querySelector('.price-line')?.textContent?.trim()).toBe('Free');
  });

  it('says where the paid tickets start, beside a free one', async () => {
    const page = await open(detail({ tiers: [['General', 0], ['VIP', 4500]] }));

    expect(page.querySelector('.price-line')?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Free, or from $45.00');
    expect(page.textContent).toContain('or from $45.00');
  });

  it('still says "From" a price that is not free', async () => {
    const page = await open(detail({ from_price: { amount: 2500, currency: 'CAD' }, tiers: [['General', 2500]] }));

    expect(page.querySelector('.price-line')?.textContent?.replace(/\s+/g, ' ').trim()).toBe('From $25.00');
  });
});

/**
 * Share, in a browser that will neither share nor copy.
 *
 * With no share sheet and the clipboard refused, the button did nothing at
 * all: the refusal was caught and dropped. The link is shown to copy instead.
 */
describe('EventDetail, sharing', () => {
  let http: HttpTestingController;
  const original = { share: navigator.share, clipboard: navigator.clipboard };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: ':slug', component: EventDetail }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    Object.defineProperty(navigator, 'share', { value: original.share, configurable: true });
    Object.defineProperty(navigator, 'clipboard', { value: original.clipboard, configurable: true });
  });

  it('shows the link to copy by hand when the clipboard is refused', async () => {
    Object.defineProperty(navigator, 'share', { value: undefined, configurable: true });
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: () => Promise.reject(new DOMException('Write permission denied.', 'NotAllowedError')) },
      configurable: true,
    });

    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl('/qa-free-night', EventDetail);
    http.expectOne('https://api.myfiesta.test/api/events/qa-free-night').flush({ data: detail() });
    http.match(() => true).forEach((request) => request.flush({}));
    harness.detectChanges();

    await page.share();
    harness.detectChanges();

    const link = (harness.routeNativeElement as HTMLElement).querySelector<HTMLInputElement>('#share-link');
    expect(link?.value).toBe('https://myfiesta.ca/qa-free-night');
    expect(page.shared()).toBe(false);
  });

  it('says nothing more when somebody closes the share sheet', async () => {
    Object.defineProperty(navigator, 'share', {
      value: () => Promise.reject(new DOMException('Share canceled', 'AbortError')),
      configurable: true,
    });

    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl('/qa-free-night', EventDetail);
    http.expectOne('https://api.myfiesta.test/api/events/qa-free-night').flush({ data: detail() });
    http.match(() => true).forEach((request) => request.flush({}));
    harness.detectChanges();

    await page.share();
    harness.detectChanges();

    expect((harness.routeNativeElement as HTMLElement).querySelector('#share-link')).toBeNull();
  });
});

/**
 * The rail beside the words, on a wide screen.
 *
 * It always stuck, held to the window's height with a scroll of its own. At
 * 1280×720 the rail is taller than that, and "See all their events" sat
 * behind a scroll nobody knew was there. It sticks now only when all of it
 * fits below the header, and otherwise scrolls with the page.
 */
describe('EventDetail, the rail', () => {
  let http: HttpTestingController;
  let windowHeight: number;
  let header: HTMLStyleElement;

  /** The rail as the free night draws it on a laptop: 745px, under an 88px header. */
  const RAIL = 745;

  beforeEach(() => {
    windowHeight = 900;

    // jsdom lays nothing out: the heights a browser would measure, and the
    // observer it would have.
    vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} });
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function (this: HTMLElement) {
      return this.classList.contains('rail') ? RAIL : 0;
    });
    vi.spyOn(Element.prototype, 'clientHeight', 'get').mockImplementation(function (this: Element) {
      return this === document.documentElement ? windowHeight : 0;
    });
    header = document.head.appendChild(document.createElement('style'));
    header.textContent = '.rail { top: 88px; }';

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: ':slug', component: EventDetail }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    header.remove();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  async function open(): Promise<{ harness: RouterTestingHarness; rail: HTMLElement }> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/qa-free-night', EventDetail);
    http.expectOne('https://api.myfiesta.test/api/events/qa-free-night').flush({ data: detail() });
    http.match(() => true).forEach((request) => request.flush({}));
    harness.detectChanges();

    return { harness, rail: (harness.routeNativeElement as HTMLElement).querySelector<HTMLElement>('.rail')! };
  }

  it('never keeps part of itself behind a scroll of its own', async () => {
    const { rail } = await open();

    expect(rail.className).not.toMatch(/overflow-y-auto|max-h-/);
  });

  it('sticks when all of it fits below the header, as at 1440×900', async () => {
    const { rail } = await open();

    expect(rail.classList).toContain('sticks');
  });

  it('scrolls with the page when it is taller than that, as at 1280×720, and sticks again when there is room', async () => {
    windowHeight = 720;
    const { harness, rail } = await open();

    expect(rail.classList).not.toContain('sticks');

    windowHeight = 900;
    window.dispatchEvent(new Event('resize'));
    harness.detectChanges();

    expect(rail.classList).toContain('sticks');
  });

  it('counts the header it sticks under', async () => {
    // Room for the rail in the window, but not below the header.
    windowHeight = 800;
    const { rail } = await open();

    expect(rail.classList).not.toContain('sticks');
  });
});
