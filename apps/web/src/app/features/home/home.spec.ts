import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api-base';
import { Discovery, EventSummary } from '../../core/api.types';
import { CONSOLE_URL } from '../../core/console-url';
import { STORE_LINKS } from '../../core/store-links';
import { Home } from './home';

let made = 0;

const DAY = 86_400_000;

/** A time this many days from whenever the spec runs: a card over or to come never depends on the date. */
const inDays = (days: number) => new Date(Date.now() + days * DAY).toISOString();

function night(overrides: Partial<EventSummary> = {}): EventSummary {
  made += 1;

  return {
    slug: `night-${made}`,
    title: `Night ${made}`,
    starts_at: inDays(5),
    timezone: 'America/Toronto',
    city: 'Toronto',
    country: 'CA',
    currency: 'CAD',
    category: 'Nightlife',
    poster_url: `https://cdn.test/poster-${made}.jpg`,
    organizer: { name: 'Lagos Nights', slug: 'lagos-nights' },
    from_price: { amount: 2500, currency: 'CAD' },
    is_sold_out: false,
    availability: { state: 'available', left: null },
    waitlist: false,
    ...overrides,
  } as EventSummary;
}

function discovery(overrides: Partial<Discovery> = {}): Discovery {
  const tonight = night({ title: 'Tonight Only' });

  return {
    featured: [night({ title: 'The Lead Night' })],
    upcoming: [tonight, night({ title: 'Next Month' })],
    today: [tonight],
    weekend: [night({ title: 'Saturday Thing' })],
    almost_sold_out: [night({ title: 'Going Fast', availability: { state: 'almost_sold_out', left: null } })],
    sold_out: [night({ title: 'All Gone', is_sold_out: true, availability: { state: 'sold_out', left: null }, waitlist: true })],
    past: [night({ title: 'Last Week' })],
    categories: [
      { category: 'Food & drink', slug: 'food-and-drink', events: 3, cover_url: null },
      { category: 'Comedy', slug: 'comedy', events: 1, cover_url: 'https://cdn.test/comedy.jpg' },
    ],
    cities: [
      { city: 'Toronto', country: 'CA', slug: 'toronto', events: 5, cover_url: 'https://cdn.test/toronto.jpg' },
      { city: 'Lagos', country: 'NG', slug: 'lagos', events: 2, cover_url: null },
    ],
    totals: { upcoming: 7, cities: 2 },
    fees: { service_charge: { CAD: '8', NGN: '8' } },
    ...overrides,
  };
}

/**
 * The front page: made of what is actually on, every shelf a question
 * somebody asks, and nothing on it the database cannot answer for.
 */
describe('Home', () => {
  let http: HttpTestingController;
  let stores: { ios: string; android: string };

  beforeEach(() => {
    made = 0;
    stores = { ios: '', android: '' };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: CONSOLE_URL, useValue: 'https://console.myfiesta.test' },
        { provide: STORE_LINKS, useFactory: () => stores },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function open(answer: Discovery = discovery()) {
    const fixture = TestBed.createComponent(Home);
    http.expectOne('https://api.myfiesta.test/api/discover').flush(answer);
    fixture.detectChanges();

    return { fixture, page: fixture.nativeElement as HTMLElement };
  }

  const headings = (page: HTMLElement) => [...page.querySelectorAll('h2')].map((h) => h.textContent?.trim() ?? '');

  it('has a shelf for each question, in the order somebody asks them', () => {
    const { page } = open();

    const shelves = headings(page).filter((h) =>
      ['Happening today', 'This weekend', 'Almost sold out', 'Browse by category', 'Coming up', 'Events by city', 'Sold out', 'Recently'].includes(h),
    );

    expect(shelves).toEqual(['Happening today', 'This weekend', 'Almost sold out', 'Browse by category', 'Coming up', 'Events by city', 'Sold out', 'Recently']);
  });

  it('leaves a shelf out rather than showing it empty', () => {
    const { page } = open(discovery({ today: [], sold_out: [], past: [] }));

    expect(headings(page)).not.toContain('Happening today');
    expect(headings(page)).not.toContain('Sold out');
    expect(headings(page)).not.toContain('Recently');
  });

  it('does not show tonight again under "Coming up"', () => {
    const { fixture } = open();

    expect(fixture.componentInstance.shelves().upcoming?.events.map((e) => e.title)).toEqual(['Next Month']);
  });

  it('fills "Coming up" from the API\'s own later shelf when it sends one', () => {
    const { fixture } = open(discovery({ later: [night({ title: 'Next Tuesday' }), night({ title: 'In Two Weeks' })] }));

    expect(fixture.componentInstance.shelves().upcoming?.events.map((e) => e.title)).toEqual(['Next Tuesday', 'In Two Weeks']);
  });

  it('loads the lead poster first and every other picture lazily', () => {
    const { page } = open();

    const lead = page.querySelector('.mosaic__lead img');
    expect(lead?.getAttribute('fetchpriority')).toBe('high');
    expect(lead?.getAttribute('loading')).toBe('eager');

    const others = [...page.querySelectorAll('img')].filter((img) => img !== lead && !img.classList.contains('hero__glow'));
    expect(others.length).toBeGreaterThan(0);
    for (const img of others) expect(img.getAttribute('loading')).toBe('lazy');
  });

  it('states only counted figures', () => {
    const { page } = open();

    expect(page.querySelector('.hero__eyebrow')?.textContent).toContain('7 events coming up in 2 cities');
  });

  it('gives each category and city its own page, and draws a category with no poster', () => {
    const { page } = open();

    expect(page.querySelector('a[href="/events/category/food-and-drink"]')?.querySelector('app-poster-art')).not.toBeNull();
    expect(page.querySelector('a[href="/events/category/comedy"] img')?.getAttribute('src')).toBe('https://cdn.test/comedy.jpg');
    expect(page.querySelector('a[href="/events/city/lagos"]')?.textContent).toContain('Nigeria');
  });

  it('sends a sold-out night to its waitlist', () => {
    const { page } = open();

    const card = [...page.querySelectorAll('app-event-card')].find((c) => c.textContent?.includes('All Gone'));
    expect(card?.textContent).toContain('Sold out');
    expect(card?.textContent).toContain('Join waitlist');
  });

  it('draws a sold-out night that has happened as past, not as one to wait for', () => {
    const { page } = open(
      discovery({
        sold_out: [
          night({ title: 'All Gone', is_sold_out: true, availability: { state: 'sold_out', left: null }, waitlist: true }),
          night({
            title: 'Went Last Week',
            starts_at: inDays(-7),
            ends_at: inDays(-6.8),
            is_sold_out: true,
            availability: { state: 'sold_out', left: null },
            waitlist: false,
          }),
          // No end given: over half a day after it started.
          night({ title: 'Went Yesterday', starts_at: inDays(-1), is_sold_out: true, availability: { state: 'sold_out', left: null } }),
        ],
      }),
    );

    const card = (title: string) => [...page.querySelectorAll('app-event-card')].find((c) => c.textContent?.includes(title));

    expect(card('All Gone')?.textContent).toContain('Join waitlist');
    for (const title of ['Went Last Week', 'Went Yesterday']) {
      expect(card(title)?.textContent).toContain('Past event');
      expect(card(title)?.textContent).not.toContain('Join waitlist');
    }
  });

  it('hands what, where and when to the listing', () => {
    const { fixture } = open();
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);

    fixture.componentInstance.query.set(' afrobeats ');
    fixture.componentInstance.city.set('Lagos');
    fixture.componentInstance.when.set('weekend');
    fixture.componentInstance.search();

    expect(navigate).toHaveBeenCalledWith(['/events'], { queryParams: { q: 'afrobeats', city: 'Lagos', when: 'weekend' } });
  });

  it('offers only cities with something on in the search', () => {
    const { page } = open();

    const cities = [...page.querySelectorAll('select[name="city"] option')].map((o) => o.textContent?.trim());
    expect(cities).toEqual(['Anywhere', 'Toronto', 'Lagos']);
  });

  it('tells organizers the service charge the platform actually charges', () => {
    const { page } = open();

    const pitch = page.querySelector('app-organizer-pitch');
    expect(pitch?.textContent).toContain('Our 8% service charge');
    expect(pitch?.querySelector('a[href="https://console.myfiesta.test/register"]')).not.toBeNull();
  });

  it('hides the app promo until a store listing exists', () => {
    expect(open().page.querySelector('#promo-title')).toBeNull();
  });

  it('shows a store button for each listing it has', () => {
    stores = { ios: 'https://apps.apple.test/app/myfiesta', android: '' };
    const { page } = open();

    expect(page.querySelector('#promo-title')).not.toBeNull();
    expect(page.querySelector('a[href="https://apps.apple.test/app/myfiesta"]')?.textContent).toContain('App Store');
    expect(page.textContent).not.toContain('Google Play');
  });

  it('says it could not load, rather than that nothing is on', () => {
    const fixture = TestBed.createComponent(Home);
    http.expectOne('https://api.myfiesta.test/api/discover').flush({}, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    const page = fixture.nativeElement as HTMLElement;
    expect(page.querySelector('[role="alert"]')?.textContent).toContain('could not load');
    expect(page.textContent).not.toContain('Nothing on sale');
  });
});
