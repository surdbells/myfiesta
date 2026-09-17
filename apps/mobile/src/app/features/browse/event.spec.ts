import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Event } from './event';
import { Discover, EventPage, TicketTypeCard } from '../../core/discovery';

vi.mock('@capacitor/browser', () => ({ Browser: { open: async () => undefined } }));
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

  beforeEach(() => {
    joins = [];
    joinAnswer = async () => ({ message: "You're on the waitlist." });

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        {
          provide: Discover,
          useValue: {
            event: async () => showing,
            siteBase: () => 'https://myfiesta.test',
            waitlist: async (slug: string, body: { name: string; email: string; quantity: number }) => {
              joins.push({ slug, body });

              return joinAnswer();
            },
          },
        },
      ],
    });
  });

  async function open(event: EventPage) {
    showing = event;
    const fixture = TestBed.createComponent(Event);
    fixture.componentRef.setInput('slug', event.slug);
    fixture.autoDetectChanges();
    await fixture.whenStable();
    page = fixture.componentInstance;

    return fixture;
  }

  it('offers the cheapest way in when something is on sale', async () => {
    await open(night({ ticket_types: [tier({ price: { amount: 9000, currency: 'CAD' } }), tier()] }));

    expect(page.soldOut()).toBe(false);
    expect(page.anyTickets()).toBe(true);
    expect(page.from(showing)).toContain('30.00');
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
