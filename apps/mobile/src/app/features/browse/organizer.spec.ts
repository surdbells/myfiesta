import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it } from 'vitest';
import { Organizer } from './organizer';
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

  beforeEach(() => {
    follows = [];
    followAnswer = async () => ({ following: true });
    signedIn = true;
    load = async () => showing;

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

  it('dates a night that has gone without its clock', async () => {
    await open(organizer());

    const was = page.wasWhen(card({ starts_at: '2025-08-28T22:00:00Z' }));

    // The year, because a date without one reads as a night still to come;
    // no time, because when somebody should have arrived is no use now.
    expect(was).toContain('2025');
    expect(was).not.toMatch(/\d{1,2}:\d{2}/);
  });
});
