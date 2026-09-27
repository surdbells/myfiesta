import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Event } from './event';
import { Discover, EventPage, TicketTypeCard } from '../../core/discovery';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';

const browser = vi.hoisted(() => ({ opened: [] as string[] }));

vi.mock('@capacitor/browser', () => ({
  Browser: {
    open: async ({ url }: { url: string }) => {
      browser.opened.push(url);
    },
  },
}));
vi.mock('@capacitor/share', () => ({ Share: { share: async () => undefined } }));

const tier = (over: Partial<TicketTypeCard> = {}): TicketTypeCard => ({
  id: over.id ?? 'tier-' + Math.random(),
  name: 'General',
  description: null,
  price: { amount: 3000, currency: 'CAD' },
  remaining: null,
  sold_out: false,
  waiting: false,
  opens_after: null,
  max_per_order: null,
  ...over,
});

const night = (over: Partial<EventPage> = {}): EventPage =>
  ({
    slug: 'afro-fest',
    title: 'Afro Fest',
    starts_at: new Date(Date.now() + 86_400_000).toISOString(),
    ends_at: null,
    timezone: 'America/Toronto',
    city: 'Toronto',
    country: 'CA',
    currency: 'CAD',
    category: 'nightlife',
    poster_url: null,
    organizer: { name: 'Lagos Nights', slug: 'lagos-nights' },
    from_price: { amount: 3000, currency: 'CAD' },
    description: null,
    description_text: null,
    dress_code: null,
    min_age: null,
    id_required: false,
    venue: null,
    gallery: [],
    ticket_types: [tier()],
    calendar: { ics_url: '', google_url: '' },
    ...over,
  }) as EventPage;

/**
 * What the bar along the bottom says.
 *
 * Three different states that look similar and mean opposite things: tickets
 * you can buy, tickets that are gone, and an event whose organizer has not
 * opened any yet. Offering "Get tickets" for the last two is the bug worth a
 * test — it sends somebody to a checkout with nothing in it.
 */
describe('Event page', () => {
  let page: Event;
  let showing: EventPage;
  let joins: { slug: string; body: { name: string; email: string; quantity: number } }[];
  let joinAnswer: () => Promise<{ message: string }>;
  let saves: { slug: string; on: boolean }[];
  let saveAnswer: () => Promise<{ saved: boolean }>;
  let signedIn: boolean;

  beforeEach(() => {
    joins = [];
    joinAnswer = async () => ({ message: "You're on the waitlist." });
    saves = [];
    saveAnswer = async () => ({ saved: true });
    signedIn = true;

    TestBed.configureTestingModule({
      providers: [
        // A stub for the one route the page navigates to itself.
        provideRouter([{ path: 'sign-in', children: [] }]),
        {
          provide: Discover,
          useValue: {
            event: async () => showing,
            siteBase: () => 'https://myfiesta.test',
            waitlist: async (slug: string, body: { name: string; email: string; quantity: number }) => {
              joins.push({ slug, body });

              return joinAnswer();
            },
            save: async (slug: string, on: boolean) => {
              saves.push({ slug, on });

              return saveAnswer();
            },
            follow: async () => ({ following: true }),
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
  });

  async function open(event: EventPage, ref?: string) {
    showing = event;
    const fixture = TestBed.createComponent(Event);
    fixture.componentRef.setInput('slug', event.slug);
    // As the router binds `?ref=` from the address, when there is one.
    if (ref !== undefined) fixture.componentRef.setInput('ref', ref);
    fixture.autoDetectChanges();
    await fixture.whenStable();
    page = fixture.componentInstance;

    return fixture;
  }

  it('offers the cheapest way in when something is on sale', async () => {
    await open(night({ ticket_types: [tier({ price: { amount: 9000, currency: 'CAD' } }), tier()] }));

    expect(page.soldOut()).toBe(false);
    expect(page.anyTickets()).toBe(true);
    // Against the formatter rather than a spelled-out "$30.00", which is only
    // how an English phone writes it.
    expect(page.from(showing)).toBe(`From ${formatMoney({ amount: 3000, currency: 'CAD' })}`);
  });

  it('calls it sold out only when there were tickets to sell', async () => {
    await open(night({ ticket_types: [tier({ sold_out: true }), tier({ waiting: true })] }));
    expect(page.soldOut()).toBe(true);

    await open(night({ ticket_types: [] }));
    // No tiers at all is an organizer who has not opened sales, not a
    // sell-out — and the two want different words on the button.
    expect(page.soldOut()).toBe(false);
    expect(page.anyTickets()).toBe(false);
  });

  /**
   * The waitlist is filled in here rather than on the website. Somebody who
   * could not buy a ticket has already been disappointed once; sending them to
   * a browser to type their address is the second time.
   */
  describe('the waitlist', () => {
    async function soldOut() {
      return open(night({ ticket_types: [tier({ sold_out: true })] }));
    }

    it('will not post without an address to tell anyone on', async () => {
      await soldOut();
      page.email.set('  ');

      await page.join();

      expect(joins).toEqual([]);
      expect(page.wrong()).toBeTruthy();
    });

    it('sends the name, the address and how many, and shows what the server said', async () => {
      await soldOut();
      page.name.set(' Ada ');
      page.email.set(' ada@example.test ');
      page.quantity.set('3');

      await page.join();

      expect(joins).toEqual([
        { slug: 'afro-fest', body: { name: 'Ada', email: 'ada@example.test', quantity: 3 } },
      ]);
      expect(page.joined()).toBe("You're on the waitlist.");
      expect(page.wrong()).toBeNull();
    });

    it('keeps the form open with the reason when the server refuses', async () => {
      await soldOut();
      page.email.set('ada@example.test');
      joinAnswer = async () => {
        throw new Error('Tickets are on sale right now — no need to wait.');
      };

      await page.join();

      expect(page.joined()).toBeNull();
      expect(page.wrong()).toContain('on sale right now');
    });
  });

  /**
   * Saving answers on the phone first.
   *
   * A control that waits for a round trip before it changes reads as broken,
   * and somebody taps it again. It fills immediately and puts itself back if
   * the server disagrees.
   */
  describe('saving', () => {
    it('fills in straight away and stays filled when the server agrees', async () => {
      await open(night({ saved: false } as Partial<EventPage>));

      const tapped = page.toggleSave();
      expect(page.saved()).toBe(true);

      await tapped;
      expect(saves).toEqual([{ slug: 'afro-fest', on: true }]);
      expect(page.saved()).toBe(true);
    });

    it('puts itself back when the save fails', async () => {
      await open(night({ saved: false } as Partial<EventPage>));
      saveAnswer = async () => {
        throw new Error('nope');
      };

      await page.toggleSave();

      expect(page.saved()).toBe(false);
    });

    it('reads the state the server sent rather than assuming', async () => {
      await open(night({ saved: true, organizer: { name: 'Lagos Nights', slug: 'lagos-nights', following: true } } as Partial<EventPage>));

      expect(page.saved()).toBe(true);
      expect(page.following()).toBe(true);
    });

    it('asks a guest to sign in instead of quietly doing nothing', async () => {
      signedIn = false;
      await open(night());

      await page.toggleSave();

      expect(saves).toEqual([]);
      expect(page.saved()).toBe(false);
      // And comes back here afterwards rather than dropping them on a tickets
      // screen they did not ask for.
      expect(TestBed.inject(Router).url).toContain('/sign-in?next=%2Fe%2Fafro-fest');
    });
  });

  /**
   * A promoter's link opens this screen with `?ref=`, and the checkout is
   * the site's. Dropping the ref on the way to the ticket page is a
   * promoter not paid for the sale, a discount not given and a presale tier
   * that stays shut — so it goes with the buyer, and through a sign-in.
   */
  describe("a promoter's ref", () => {
    beforeEach(() => {
      browser.opened = [];
    });

    it('goes with the buyer to the ticket page', async () => {
      await open(night(), 'dj-kay');

      await page.buy(showing);

      expect(browser.opened).toEqual(['https://myfiesta.test/afro-fest/tickets?ref=dj-kay']);
    });

    it('is carried as a value, whatever it has in it', async () => {
      await open(night(), 'a&b=c <x>');

      await page.buy(showing);

      const opened = new URL(browser.opened[0]);
      expect(opened.pathname).toBe('/afro-fest/tickets');
      expect(opened.searchParams.get('ref')).toBe('a&b=c <x>');
      expect([...opened.searchParams.keys()]).toEqual(['ref']);
    });

    it('adds nothing when no promoter sent them', async () => {
      await open(night());
      await page.buy(showing);

      await open(night(), '  ');
      await page.buy(showing);

      expect(browser.opened).toEqual(['https://myfiesta.test/afro-fest/tickets', 'https://myfiesta.test/afro-fest/tickets']);
    });

    it('survives a sign-in that interrupts them', async () => {
      signedIn = false;
      await open(night(), 'dj-kay');

      await page.toggleSave();

      const next = TestBed.inject(Router).parseUrl(TestBed.inject(Router).url).queryParams['next'];
      expect(next).toBe('/e/afro-fest?ref=dj-kay');
    });
  });

  it('knows a night that has already happened', async () => {
    await open(
      night({
        starts_at: new Date(Date.now() - 172_800_000).toISOString(),
        ends_at: new Date(Date.now() - 86_400_000).toISOString(),
      }),
    );

    expect(page.past()).toBe(true);
  });

  it('counts an event as on until its end, not its start', async () => {
    await open(
      night({
        starts_at: new Date(Date.now() - 3_600_000).toISOString(),
        ends_at: new Date(Date.now() + 3_600_000).toISOString(),
      }),
    );

    // Somebody standing outside an hour after doors is still buying a ticket.
    expect(page.past()).toBe(false);
  });
});
