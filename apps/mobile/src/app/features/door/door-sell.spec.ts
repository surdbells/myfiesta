import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DoorSell } from './door-sell';
import { Api, ApiError, DoorSale, DoorSaleRequest, Sellable } from '../../core/api';

const sellable = (over: Partial<Sellable> = {}): Sellable => ({
  currency: 'CAD',
  methods: ['cash', 'card', 'transfer'],
  ticket_types: [
    {
      id: 'general',
      name: 'General',
      price: { amount: 5000, currency: 'CAD' },
      admits: 1,
      remaining: 3,
      sold_out: false,
    },
  ],
  ...over,
});

/**
 * Selling in a doorway.
 *
 * What is worth pinning is the money and the signal. The total said out loud
 * is the server's, never one this phone added up; and a sale needs a
 * connection, because money changing hands and stock leaving the room are not
 * things a phone can decide on its own.
 */
describe('Selling at the door', () => {
  let sell: DoorSell;
  let quoted: { ticket_type_id: string; quantity: number }[][];
  let sold: DoorSaleRequest[];
  let list: () => Promise<Sellable>;
  let quote: () => Promise<{ total: { amount: number; currency: string }; tax: { amount: number; currency: string }; tax_label: string | null }>;
  let sale: () => Promise<DoorSale>;

  beforeEach(() => {
    quoted = [];
    sold = [];
    list = async () => sellable();
    quote = async () => ({
      total: { amount: 5650, currency: 'CAD' },
      tax: { amount: 650, currency: 'CAD' },
      tax_label: 'HST',
    });
    sale = async () => ({
      reference: 'ABC123',
      total: { amount: 5650, currency: 'CAD' },
      method: 'cash',
      emailed: false,
      tickets: [{ id: 't1', code: 'WFY7-F77K4EJW', type: 'General', admits: 1 }],
    });

    TestBed.configureTestingModule({
      providers: [
        {
          provide: Api,
          useValue: {
            sellable: async () => list(),
            doorQuote: async (_event: string, items: { ticket_type_id: string; quantity: number }[]) => {
              quoted.push(items);

              return quote();
            },
            sellAtDoor: async (_event: string, body: DoorSaleRequest) => {
              sold.push(body);

              return sale();
            },
          },
        },
      ],
    });
  });

  async function open() {
    const fixture = TestBed.createComponent(DoorSell);
    fixture.componentRef.setInput('eventId', 'event-1');
    fixture.componentRef.setInput('open', true);
    sell = fixture.componentInstance;

    await sell.load();

    return fixture;
  }

  it('offers what the server says is left', async () => {
    await open();

    expect(sell.tiers()[0].name).toBe('General');
    expect(sell.failed()).toBeNull();
  });

  it('says what to charge from the server rather than adding it up here', async () => {
    await open();

    sell.adjust(sell.tiers()[0], 1);
    await Promise.resolve();
    await Promise.resolve();

    // A phone adding up tiers itself lands a cent out on the tax often
    // enough, and a cent is somebody holding coins while a screen disagrees.
    expect(quoted).toEqual([[{ ticket_type_id: 'general', quantity: 1 }]]);
    expect(sell.total()?.amount).toBe(5650);
  });

  it('will not sell more than is left', async () => {
    await open();

    const tier = sell.tiers()[0];

    sell.adjust(tier, 1);
    sell.adjust(tier, 1);
    sell.adjust(tier, 1);

    expect(sell.count('general')).toBe(3);
    expect(sell.atCeiling(tier)).toBe(true);
  });

  it('sends what was chosen and how they paid', async () => {
    await open();

    sell.adjust(sell.tiers()[0], 2);
    sell.method.set('transfer');

    await sell.sell();

    expect(sold).toEqual([
      {
        items: [{ ticket_type_id: 'general', quantity: 2 }],
        method: 'transfer',
        name: undefined,
        email: undefined,
      },
    ]);
  });

  it('comes back with the code, because this phone is about to scan it', async () => {
    const fixture = await open();
    const admitted: string[] = [];
    fixture.componentInstance.admitted.subscribe((code: string) => admitted.push(code));

    sell.adjust(sell.tiers()[0], 1);
    await sell.sell();

    expect(sell.sold()?.tickets[0].code).toBe('WFY7-F77K4EJW');

    sell.admit(sell.sold()!);

    expect(admitted).toEqual(['WFY7-F77K4EJW']);
  });

  it('says plainly that selling needs a connection', async () => {
    list = async () => {
      throw new ApiError('No connection. Check signal and try again.', 0);
    };

    await open();

    // Scanning survives a dead signal from the saved list; selling cannot,
    // and the screen says which is which rather than looking broken.
    expect(sell.failed()).toContain('Selling needs a connection');
  });

  it('keeps the money unsold when the sale does not go through', async () => {
    await open();
    sell.adjust(sell.tiers()[0], 1);

    sale = async () => {
      throw new ApiError('No connection. Check signal and try again.', 0);
    };

    await sell.sell();

    expect(sell.sold()).toBeNull();
    expect(sell.wrong()).toContain('Nothing has been sold');
  });

  it('refuses a sold-out tier without asking the server', async () => {
    list = async () =>
      sellable({
        ticket_types: [
          {
            id: 'general',
            name: 'General',
            price: { amount: 5000, currency: 'CAD' },
            admits: 1,
            remaining: 0,
            sold_out: true,
          },
        ],
      });

    await open();

    expect(sell.atCeiling(sell.tiers()[0])).toBe(true);
    expect(quoted).toEqual([]);
  });

  it('starts clean for the next person', async () => {
    await open();
    sell.adjust(sell.tiers()[0], 1);
    await sell.sell();

    sell.again();

    expect(sell.sold()).toBeNull();
    expect(sell.chosen()).toBe(0);
    expect(sell.total()).toBeNull();
  });
});
