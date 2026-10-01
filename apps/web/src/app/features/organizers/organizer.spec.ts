import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { EventSummary, OrganizerPage } from '../../core/api.types';
import { Organizer } from './organizer';

/**
 * An organizer link that leads nowhere.
 *
 * The page already said "not found" and carried noindex; the server still
 * answered 200. Rendered here as the server renders it, with a response to set.
 */
describe('Organizer, when there is no organizer', () => {
  let response: ResponseInit;
  let http: HttpTestingController;

  async function open(slug: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(`/o/${slug}`, Organizer);

    return harness;
  }

  beforeEach(() => {
    response = { status: 200 };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'o/:slug', component: Organizer }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: response },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('answers 404 and keeps the page out of search', async () => {
    const harness = await open('nobody');

    http
      .expectOne('https://api.myfiesta.test/api/organizers/nobody')
      .flush({ message: 'Not found.' }, { status: 404, statusText: 'Not Found' });
    harness.detectChanges();

    expect(response.status).toBe(404);
    expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
    expect(harness.routeNativeElement?.textContent).toContain('Organizer not found');
  });

  it('answers 503 when the API did not answer, rather than forgetting them', async () => {
    const harness = await open('lagos-nights');

    http
      .expectOne('https://api.myfiesta.test/api/organizers/lagos-nights')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });
    harness.detectChanges();

    expect(response.status).toBe(503);
    expect(harness.routeNativeElement?.textContent).not.toContain('Organizer not found');
  });
});

/**
 * Where else to find them, and the nights before the twelve the page brings.
 */
describe('Organizer, with a history and somewhere else to be found', () => {
  let http: HttpTestingController;

  function night(n: number): EventSummary {
    return {
      slug: `night-${n}`,
      title: `Night ${n}`,
      starts_at: '2025-10-02T01:00:00Z',
      timezone: 'America/Toronto',
      city: 'Toronto',
      country: 'CA',
      currency: 'CAD',
      category: 'Nightlife',
      poster_url: null,
      organizer: { name: 'Lagos Nights', slug: 'lagos-nights' },
      from_price: { amount: 2500, currency: 'CAD' },
      is_sold_out: false,
      availability: { state: 'available', left: null },
      waitlist: false,
    } as EventSummary;
  }

  function page(overrides: Partial<OrganizerPage> = {}): OrganizerPage {
    return {
      name: 'Lagos Nights',
      slug: 'lagos-nights',
      description: null,
      is_verified: false,
      logo_url: null,
      upcoming: [],
      past: Array.from({ length: 12 }, (_, i) => night(i + 1)),
      past_has_more: true,
      socials: [
        {
          network: 'instagram',
          label: '@lagosnights',
          url: 'https://www.instagram.com/lagosnights/',
        },
        { network: 'website', label: 'lagosnights.com', url: 'https://lagosnights.com' },
      ],
      ...overrides,
    } as OrganizerPage;
  }

  async function open(body: OrganizerPage): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/o/lagos-nights', Organizer);

    http.expectOne('https://api.myfiesta.test/api/organizers/lagos-nights').flush({ data: body });
    harness.detectChanges();

    return harness;
  }

  function moreButton(harness: RouterTestingHarness): HTMLButtonElement | undefined {
    return [...harness.routeNativeElement!.querySelectorAll('button')].find((b) =>
      b.textContent?.includes('Show more past events'),
    ) as HTMLButtonElement | undefined;
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'o/:slug', component: Organizer }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('links to their accounts in a new tab, telling those sites nothing and vouching for nothing', async () => {
    const harness = await open(page());
    const links = [
      ...harness.routeNativeElement!.querySelectorAll<HTMLAnchorElement>('ul.socials a'),
    ];

    expect(links.map((a) => a.textContent?.replace(/\s+/g, ' ').trim())).toEqual([
      'Instagram @lagosnights',
      'Website lagosnights.com',
    ]);
    expect(links[0].getAttribute('href')).toBe('https://www.instagram.com/lagosnights/');

    for (const link of links) {
      expect(link.getAttribute('target')).toBe('_blank');
      expect(link.getAttribute('rel')).toBe('noopener noreferrer nofollow');
    }
  });

  it('shows no list at all when there is nowhere else', async () => {
    const harness = await open(page({ socials: [] }));

    expect(harness.routeNativeElement!.querySelector('ul.socials')).toBeNull();
  });

  it('brings the next twelve past events, then stops offering when there are no more', async () => {
    const harness = await open(page());

    moreButton(harness)!.click();
    http
      .expectOne('https://api.myfiesta.test/api/organizers/lagos-nights/events?when=past&page=2')
      .flush({ data: [night(13), night(14)], meta: { page: 2, per_page: 12, has_more: false } });
    harness.detectChanges();

    expect(harness.routeNativeElement!.querySelectorAll('app-event-card').length).toBe(14);
    expect(moreButton(harness)).toBeUndefined();
  });

  it('never lists a night twice, should one have moved across since the page loaded', async () => {
    const harness = await open(page());

    moreButton(harness)!.click();
    http
      .expectOne('https://api.myfiesta.test/api/organizers/lagos-nights/events?when=past&page=2')
      .flush({ data: [night(12), night(13)], meta: { page: 2, per_page: 12, has_more: true } });
    harness.detectChanges();

    expect(harness.routeNativeElement!.querySelectorAll('app-event-card').length).toBe(13);
    expect(moreButton(harness)).toBeDefined();
  });

  it('says so when older events do not load, and lets the reader try again', async () => {
    const harness = await open(page());

    moreButton(harness)!.click();
    http
      .expectOne('https://api.myfiesta.test/api/organizers/lagos-nights/events?when=past&page=2')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });
    harness.detectChanges();

    expect(harness.routeNativeElement!.textContent).toContain('Older events did not load');

    moreButton(harness)!.click();
    http
      .expectOne('https://api.myfiesta.test/api/organizers/lagos-nights/events?when=past&page=2')
      .flush({ data: [night(13)], meta: { page: 2, per_page: 12, has_more: false } });
    harness.detectChanges();

    expect(harness.routeNativeElement!.textContent).not.toContain('Older events did not load');
    expect(harness.routeNativeElement!.querySelectorAll('app-event-card').length).toBe(13);
  });

  it('offers nothing more when the twelve are all there is', async () => {
    const harness = await open(page({ past_has_more: false }));

    expect(moreButton(harness)).toBeUndefined();
  });
});
