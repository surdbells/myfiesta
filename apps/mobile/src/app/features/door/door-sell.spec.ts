import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DoorSell } from './door-sell';
import { Api, ApiError, DoorSale, DoorSaleRequest, Sellable } from '../../core/api';
import { Dialogs, type ConfirmRequest } from '../../ui';

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
  let asked: ConfirmRequest[];
  let yes: boolean;

  beforeEach(() => {
    quoted = [];
    sold = [];
    asked = [];
    yes = true;
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
        // The person holding the phone, reading the question and answering it.
        {
          provide: Dialogs,
          useValue: {
            confirm: async (request: ConfirmRequest) => {
              asked.push(request);

              return yes;
            },
          },
        },
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

  /** The server's answer to the basket as it stands, arrived. */
  async function priced() {
    for (let round = 0; round < 5; round++) await Promise.resolve();
  }

  /** A quote the spec answers when it chooses, as a slow door connection would. */
  function slowQuotes() {
    const waiting: ((amount: number) => void)[] = [];

    quote = () =>
      new Promise((resolve) =>
        waiting.push((amount) =>
          resolve({ total: { amount, currency: 'CAD' }, tax: { amount: 0, currency: 'CAD' }, tax_label: null }),
        ),
      );

    return waiting;
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
    await priced();

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
    await priced();
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

  it('says the total and how they paid before selling, and sells nothing when the answer is no', async () => {
    await open();

    sell.adjust(sell.tiers()[0], 2);
    sell.method.set('cash');
    await priced();

    yes = false;
    await sell.sell();

    expect(asked[0].title).toBe('Take $56.50 in cash?');
    expect(asked[0].body).toBe('2 General: 2 tickets sold, and recorded as paid in cash.');
    expect(asked[0].confirmLabel).toBe('Take $56.50');
    expect(sold).toEqual([]);
    expect(sell.sold()).toBeNull();
  });

  it('keeps the money unsold when the sale does not go through', async () => {
    await open();
    sell.adjust(sell.tiers()[0], 1);
    await priced();

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
    await priced();
    await sell.sell();

    sell.again();

    expect(sell.sold()).toBeNull();
    expect(sell.chosen()).toBe(0);
    expect(sell.total()).toBeNull();
  });

  it('takes no money on the last basket’s price while the new one is being priced', async () => {
    const fixture = await open();
    const answer = slowQuotes();
    const tier = sell.tiers()[0];

    // Two General at $40.00, priced.
    sell.adjust(tier, 2);
    answer[0](4000);
    await priced();
    expect(sell.total()?.amount).toBe(4000);

    // A third, and Take payment tapped before the server has priced it.
    sell.adjust(tier, 1);
    fixture.detectChanges();

    const button = [...(fixture.nativeElement as HTMLElement).querySelectorAll('button')].find((b) =>
      b.textContent?.includes('Take payment'),
    );

    // Nothing on screen says $40.00 for three tickets, and nothing asks it.
    expect(sell.total()).toBeNull();
    expect(button?.disabled).toBe(true);

    await sell.sell();

    expect(asked).toEqual([]);
    expect(sold).toEqual([]);

    // Once priced, the question names the new figure and sells what it priced.
    answer[1](6000);
    await priced();
    await sell.sell();

    expect(asked[0].title).toBe('Take $60.00 in cash?');
    expect(asked[0].body).toBe('3 General: 3 tickets sold, and recorded as paid in cash.');
    expect(sold[0].items).toEqual([{ ticket_type_id: 'general', quantity: 3 }]);
  });

  it('keeps the newest price when an older quote answers late', async () => {
    await open();
    const answer = slowQuotes();
    const tier = sell.tiers()[0];

    sell.adjust(tier, 1);
    sell.adjust(tier, 1);

    // The answer for two arrives first; the one for one straggles in after.
    answer[1](4000);
    await priced();
    answer[0](2000);
    await priced();

    expect(sell.total()?.amount).toBe(4000);
  });

  it('forgets a quote still on its way once the next person starts', async () => {
    await open();
    const answer = slowQuotes();

    sell.adjust(sell.tiers()[0], 1);
    sell.again();
    answer[0](2000);
    await priced();

    expect(sell.total()).toBeNull();
  });
});
