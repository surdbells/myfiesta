import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const browsed = vi.hoisted(() => [] as string[]);

vi.mock('@capacitor/browser', () => ({
  Browser: {
    open: async ({ url }: { url: string }) => {
      browsed.push(url);
    },
  },
}));

import { Organizer } from './organizer';
import { OrganizerApi, type OrganizerNights } from './organizer-api';
import { Discover, EventCard, OrganizerPage } from '../../core/discovery';
import { SessionStore } from '../../core/session';

const card = (over: Partial<EventCard> = {}): EventCard =>
  ({
    slug: 'afro-fest',
    title: 'Afro Fest',
    starts_at: new Date(Date.now() + 86_400_000).toISOString(),
    timezone: 'America/Toronto',
    city: 'Toronto',
    country: 'CA',
    currency: 'CAD',
    category: null,
    poster_url: null,
    organizer: { name: 'Lagos Nights', slug: 'lagos-nights' },
    from_price: { amount: 3000, currency: 'CAD' },
    ...over,
  }) as EventCard;

const organizer = (over: Partial<OrganizerPage> = {}): OrganizerPage => ({
  slug: 'lagos-nights',
  name: 'Lagos Nights',
  description: 'Afrobeats every second Friday.',
  is_verified: true,
  logo_url: null,
  following: false,
  upcoming: [card()],
  past: [],
  ...over,
});

/**
 * The screen following somebody finally leads to.
 *
 * What is worth pinning is the follow button: it is the reason this screen
 * exists, it answers on the phone before the server has said anything, and a
 * guest tapping it has to end up somewhere that can take an account rather
 * than at a silent failure.
 */
describe('Organizer page', () => {
  let page: Organizer;
  let showing: OrganizerPage;
  let load: () => Promise<OrganizerPage>;
  let follows: { slug: string; on: boolean }[];
  let followAnswer: () => Promise<{ following: boolean }>;
  let signedIn: boolean;
  let router: Router;
  let pagesAsked: { slug: string; page: number }[];
  let nextPage: () => Promise<OrganizerNights>;

  beforeEach(() => {
    follows = [];
    followAnswer = async () => ({ following: true });
    signedIn = true;
    load = async () => showing;
    pagesAsked = [];
    nextPage = async () => ({ data: [], meta: { page: 2, per_page: 12, has_more: false } });
    browsed.length = 0;

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'sign-in', children: [] },
          { path: 'e/:slug', children: [] },
        ]),
        {
          provide: Discover,
          useValue: {
            organizer: async () => load(),
            follow: async (slug: string, on: boolean) => {
              follows.push({ slug, on });

              return followAnswer();
            },
          },
        },
        {
          provide: SessionStore,
          useValue: {
            session: () => (signedIn ? { scope: 'attendee', token: 't' } : null),
            signedIn: () => signedIn,
          },
        },
        {
          provide: OrganizerApi,
          useValue: {
            past: async (slug: string, page: number) => {
              pagesAsked.push({ slug, page });

              return nextPage();
            },
          },
        },
      ],
    });

    router = TestBed.inject(Router);
  });

  async function open(who: OrganizerPage) {
    showing = who;
    const fixture = TestBed.createComponent(Organizer);
    fixture.componentRef.setInput('slug', who.slug);
    fixture.autoDetectChanges();
    await fixture.whenStable();
    page = fixture.componentInstance;

    return fixture;
  }

  it('shows what they have on and what they have run', async () => {
    await open(organizer({ past: [card({ slug: 'last-month', title: 'Last Month' })] }));

    expect(page.organizer()?.name).toBe('Lagos Nights');
    expect(page.organizer()?.upcoming.map((e) => e.slug)).toEqual(['afro-fest']);
    expect(page.organizer()?.past.map((e) => e.slug)).toEqual(['last-month']);
    expect(page.failed()).toBeNull();
  });

  it('starts from what the server says about this reader', async () => {
    await open(organizer({ following: true }));

    expect(page.following()).toBe(true);
  });

  it('follows on the tap and stays followed when the server agrees', async () => {
    await open(organizer());

    const tapped = page.toggleFollow(showing);
    // Before the round trip: a button that waits reads as a broken one.
    expect(page.following()).toBe(true);

    await tapped;

    expect(follows).toEqual([{ slug: 'lagos-nights', on: true }]);
    expect(page.following()).toBe(true);
  });

  it('puts the button back when the server refuses', async () => {
    await open(organizer());
    followAnswer = async () => {
      throw new Error('nope');
    };

    await page.toggleFollow(showing);

    expect(page.following()).toBe(false);
  });

  it('sends a guest to sign in, and asks the server for nothing', async () => {
    signedIn = false;
    await open(organizer());

    await page.toggleFollow(showing);

    expect(follows).toEqual([]);
    expect(page.following()).toBe(false);
    // Back here afterwards, not on somebody else's screen.
    expect(router.url).toContain('/sign-in');
    expect(router.url).toContain('%2Fo%2Flagos-nights');
  });

  it('says so when the page cannot be loaded', async () => {
    load = async () => {
      throw new Error('Could not reach the server.');
    };

    await open(organizer());

    expect(page.failed()).toBe('Could not reach the server.');
    expect(page.organizer()).toBeNull();
  });

  it('badges a night going fast, and says sold out instead of a price nobody can pay', async () => {
    const fixture = await open(
      organizer({
        upcoming: [
          card({ slug: 'going', availability: { state: 'almost_sold_out', left: 3 } }),
          card({ slug: 'gone', is_sold_out: true, availability: { state: 'sold_out', left: null } }),
          card({ slug: 'plenty', availability: { state: 'available', left: null } }),
          card({ slug: 'closed', availability: { state: 'closed', left: null } }),
        ],
      }),
    );

    const rows = [...(fixture.nativeElement as HTMLElement).querySelectorAll('mf-card[tappable]')].map((row) => row.textContent ?? '');

    // A card never names the count; that belongs beside the ticket.
    expect(rows[0]).toContain('Almost sold out');
    expect(rows[0]).not.toContain('3 left');
    expect(rows[1]).toContain('Sold out');
    expect(rows[1]).not.toMatch(/\$\s?30/);
    expect(rows[2]).not.toMatch(/sold out/i);
    // Stopped selling with places left: said as it is, and no price either.
    expect(rows[3]).toContain('Sales closed');
    expect(rows[3]).not.toMatch(/sold out/i);
    expect(rows[3]).not.toMatch(/\$\s?30/);
  });

  it('dates a night that has gone without its clock', async () => {
    await open(organizer());

    const was = page.wasWhen(card({ starts_at: '2025-08-28T22:00:00Z' }));

    // The year, because a date without one reads as a night still to come;
    // no time, because when somebody should have arrived is no use now.
    expect(was).toContain('2025');
    expect(was).not.toMatch(/\d{1,2}:\d{2}/);
  });

  it('lists where else to find them, and opens one in the phone’s own browser', async () => {
    const fixture = await open(
      organizer({
        socials: [
          { network: 'instagram', label: '@lagosnights', url: 'https://www.instagram.com/lagosnights/' },
          { network: 'website', label: 'lagosnights.com', url: 'https://lagosnights.com' },
        ],
      }),
    );

    const links = [...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('.socials button')];
    expect(links.map((link) => link.textContent?.replace(/\s+/g, ' ').trim())).toEqual([
      'Instagram @lagosnights',
      'Website lagosnights.com',
    ]);

    links[0].click();
    await fixture.whenStable();

    expect(browsed).toEqual(['https://www.instagram.com/lagosnights/']);
  });

  it('shows no list when there is nowhere else, or the server does not say', async () => {
    const fixture = await open(organizer());

    expect((fixture.nativeElement as HTMLElement).querySelector('.socials')).toBeNull();
  });

  it('brings older nights twelve at a time, never listing one twice, until there are no more', async () => {
    const past = Array.from({ length: 12 }, (_, i) => card({ slug: `night-${i + 1}` }));
    const fixture = await open(organizer({ past, past_has_more: true }));
    const more = () =>
      [...(fixture.nativeElement as HTMLElement).querySelectorAll('button')].find((b) => b.textContent?.includes('Show more past events'));

    expect(more()).toBeDefined();

    nextPage = async () => ({ data: [card({ slug: 'night-12' }), card({ slug: 'night-13' })], meta: { page: 2, per_page: 12, has_more: false } });
    await page.showMorePast(showing);
    await fixture.whenStable();

    expect(pagesAsked).toEqual([{ slug: 'lagos-nights', page: 2 }]);
    expect(page.past().map((event) => event.slug)).toHaveLength(13);
    expect(more()).toBeUndefined();
  });

  it('says so when older nights do not load, and asks for the same page again', async () => {
    await open(organizer({ past: [card({ slug: 'night-1' })], past_has_more: true }));

    nextPage = async () => {
      throw new Error('Could not reach the server.');
    };
    await page.showMorePast(showing);

    expect(page.pastFailed()).toBe(true);
    expect(page.pastHasMore()).toBe(true);

    nextPage = async () => ({ data: [card({ slug: 'night-2' })], meta: { page: 2, per_page: 12, has_more: false } });
    await page.showMorePast(showing);

    expect(pagesAsked.map((asked) => asked.page)).toEqual([2, 2]);
    expect(page.pastFailed()).toBe(false);
    expect(page.past().map((event) => event.slug)).toEqual(['night-1', 'night-2']);
  });
});
