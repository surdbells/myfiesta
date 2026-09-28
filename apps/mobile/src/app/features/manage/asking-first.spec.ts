import type { Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import type { OrganizerEventDetail, PayoutStatement, SoldOrder, TeamPage } from '@myfiesta/api-types';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Discover } from '../../core/discovery';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { Dialogs, ToastStore, type ConfirmRequest } from '../../ui';
import { confirmReady } from '../../ui/dialogs';
import { EventCodes } from './event-codes';
import { EventContext } from './event-context';
import { EventHub } from './event-hub';
import { EventMessages } from './event-messages';
import { EventOrders } from './event-orders';
import { EventTickets } from './event-tickets';
import { OrgPayouts } from './org-payouts';
import { OrgTeam } from './org-team';

const cad = (amount: number) => ({ amount, currency: 'CAD' as const });

const night = (over: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail => ({
  id: 'ev-1',
  slug: 'afro-fest',
  title: 'Afro Fest',
  kind: 'ticketed',
  status: 'published',
  starts_at: '2026-11-14T01:00:00Z',
  timezone: 'America/Toronto',
  city: 'Toronto',
  currency: 'CAD',
  tickets_issued: 12,
  checked_in: 0,
  orders: 6,
  capacity: null,
  revenue: null,
  views: 0,
  last_sale_at: null,
  poster_url: null,
  description: null,
  ends_at: null,
  subdivision: 'ON',
  country: 'CA',
  category: null,
  min_age: null,
  id_required: false,
  resale_enabled: false,
  resale_closes_hours: 24,
  review: {
    submitted_at: null,
    approved_at: '2026-09-01T12:00:00Z',
    on_submit: null,
    unchanged_since_approval: true,
    not_ready: [],
    rejection: null,
    history: [],
  },
  ...over,
});

const team: TeamPage = {
  members: [{ id: 'u-1', name: 'Ada Okafor', email: 'ada@example.test', role: 'owner', joined_at: null, is_you: true }],
  invitations: [],
  roles: [
    { value: 'owner', label: 'Owner', description: 'do everything, including where payouts go' },
    { value: 'finance', label: 'Finance', description: 'see orders and payouts' },
  ],
};

const statement: PayoutStatement = {
  currency: 'CAD',
  balance: cad(20_000),
  settled: cad(0),
  overdraft: null,
  events: [],
  settlements: [],
  destination: {
    rail: 'interac',
    currency: 'CAD',
    interac_email: 'money@lagosnights.test',
    bank_name: null,
    account_name: null,
    account_last_four: null,
    verified_at: null,
  },
  requests: [],
  can_request: true,
  can_change_destination: true,
};

const order: SoldOrder = {
  id: 'ord-1',
  reference: 'MF-7Q2K',
  buyer_name: 'Chidi Eze',
  buyer_email: 'chidi@example.test',
  status: 'paid',
  paid_at: '2026-10-01T18:00:00Z',
  currency: 'CAD',
  total: cad(8_000),
  refunded: cad(0),
  refundable: cad(8_000),
  tickets: [
    { id: 't-1', holder_name: 'Chidi Eze', ticket_type_name: 'General', status: 'valid', refundable: true },
    { id: 't-2', holder_name: 'Ngozi Eze', ticket_type_name: 'General', status: 'valid', refundable: true },
  ],
};

/**
 * The phone's organizer actions, each asked about first.
 *
 * The sheet itself is tested with the kit (dialogs.spec). Here it is the
 * person reading the question: what it says is kept, and saying no must
 * leave the server untouched — no invitation, no payout request, no refund,
 * no email, no new ticket type.
 */
describe('asking before an organizer action', () => {
  let asked: ConfirmRequest[];
  let organizer: Record<string, ReturnType<typeof vi.fn>>;
  let toasts: { show: ReturnType<typeof vi.fn> };
  let chosen: string | null;
  let typed: string | null;

  beforeEach(() => {
    asked = [];
    chosen = null;
    typed = null;
    toasts = { show: vi.fn() };
    organizer = {
      team: vi.fn(async () => team),
      invite: vi.fn(async () => ({ message: 'Invitation sent.' })),
      payouts: vi.fn(async () => statement),
      requestPayout: vi.fn(async () => ({ message: 'Payout requested.' })),
      refund: vi.fn(async () => ({ amount: cad(8_000) })),
      sendMessage: vi.fn(async () => ({ message: 'Sent.' })),
      createTicketType: vi.fn(async () => ({})),
      createCode: vi.fn(async () => ({})),
      createCodeBatch: vi.fn(async () => ({})),
      duplicate: vi.fn(async () => ({ id: 'ev-2' })),
      repeat: vi.fn(async () => ({ series: null, created: 5 })),
      cancellationPreview: vi.fn(async () => ({ ticket_holders: 12, orders_to_refund: 6, refund_total: cad(48_000) })),
      cancel: vi.fn(async () => ({ message: 'Cancelled.', status: 'cancelled', notified: 12, refunded: 6, failed: 0 })),
    };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Organizer, useValue: organizer },
        {
          provide: Dialogs,
          useValue: {
            confirm: async (request: ConfirmRequest) => (asked.push(request), false),
            decide: async (request: ConfirmRequest) => (asked.push(request), { confirmed: false }),
            menu: async () => chosen,
            prompt: async () => typed,
          },
        },
        { provide: ToastStore, useValue: toasts },
        { provide: SessionStore, useValue: { can: () => true, sync: vi.fn(), organization: () => null } },
        { provide: EventContext, useValue: { peek: () => night(), get: async () => night(), forget: vi.fn() } },
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.test' } },
      ],
    });
  });

  function make<T>(type: Type<T>, id?: string): T {
    const fixture = TestBed.createComponent(type);
    if (id) fixture.componentRef.setInput('id', id);

    return fixture.componentInstance;
  }

  it('names the address and the role before inviting, and invites nobody on no', async () => {
    const screen = make(OrgTeam);
    screen['page'].set(team);
    screen['email'].set('kunle@example.test');
    screen['role'].set('finance');

    await screen['invite']();

    expect(asked[0].title).toBe('Invite kunle@example.test as Finance?');
    expect(asked[0].body).toContain('they can see orders and payouts');
    expect(asked[0].confirmLabel).toBe('Send the invitation');
    expect(organizer['invite']).not.toHaveBeenCalled();
  });

  it('names the amount and where it goes before asking to be paid, and asks nothing on no', async () => {
    const screen = make(OrgPayouts);
    screen['statement'].set(statement);
    screen['askAmount'].set(15_000);

    await screen['ask']();

    expect(asked[0].title).toBe('Ask to be paid $150.00?');
    expect(asked[0].body).toContain('to Interac at money@lagosnights.test');
    expect(asked[0].consequences).toContain('The other $50.00 stays owed to you.');
    expect(organizer['requestPayout']).not.toHaveBeenCalled();
  });

  it('names the money, who gets it and what stops working before a refund, and refunds nothing on no', async () => {
    const screen = make(EventOrders, 'ev-1');
    screen['open'].set(order);
    screen['picked'].set(['t-1', 't-2']);

    await screen['refund'](order);

    expect(asked[0].title).toBe('Refund 2 tickets on MF-7Q2K?');
    expect(asked[0].body).toBe('$80.00 goes back to chidi@example.test, the way they paid.');
    expect(asked[0].tone).toBe('danger');
    expect(organizer['refund']).not.toHaveBeenCalled();
  });

  it('says how many a message reaches before it goes, and sends nothing on no', async () => {
    const screen = make(EventMessages, 'ev-1');
    screen['audience'].set({ holders: 40, reachable: 37 });
    screen['subject'].set('Doors at 9');
    screen['body'].set('Come early: the line is long after ten.');

    await screen['send']();

    expect(asked[0].title).toBe('Send “Doors at 9” to 37 people?');
    expect(asked[0].body).toContain('cannot be called back');
    expect(organizer['sendMessage']).not.toHaveBeenCalled();
  });

  it('names a new ticket type and its price before adding it, and adds nothing on no', async () => {
    const screen = make(EventTickets, 'ev-1');
    screen['event'].set(night());
    screen['startNew']();
    screen['set']('name', 'Early bird');
    screen['set']('price', 2_500);
    screen['set']('quantity', '50');

    await screen['save']();

    expect(asked[0].title).toBe('Add Early bird at $25.00?');
    expect(asked[0].body).toBe('A new ticket type, sold at $25.00, 50 in all.');
    expect(asked[0].consequences).toContain('The event is on sale: buyers see it straight away.');
    expect(organizer['createTicketType']).not.toHaveBeenCalled();
  });

  it('says a code with a From time works from then, not as soon as it is made', async () => {
    const screen = make(EventCodes, 'ev-1');
    screen['event'].set(night());
    screen['setCode']('code', 'PRESALE');
    screen['setCode']('percent', '10');
    screen['setCode']('startsAt', '2030-11-15T10:00');

    await screen['saveCode']();

    // Posted today on the word of the question, it would be refused at
    // checkout until the Friday it was set to start.
    const said = asked[0].consequences?.join(' ') ?? '';
    expect(said).toContain('It works from ');
    expect(said).toContain(', until you turn it off, for anybody who has it.');
    expect(said).not.toContain('as soon as it is made');
    expect(organizer['createCode']).not.toHaveBeenCalled();
  });

  it('says a code with no window works as soon as it is made, and until when', async () => {
    const screen = make(EventCodes, 'ev-1');
    screen['event'].set(night());
    screen['setCode']('code', 'EARLY');
    screen['setCode']('percent', '10');
    screen['setCode']('endsAt', '2030-11-15T10:00');

    await screen['saveCode']();

    const said = asked[0].consequences?.join(' ') ?? '';
    expect(said).toContain('It works as soon as it is made, until ');
    expect(said).not.toContain('until you turn it off');
  });

  it('says a batch with a From time works from then', async () => {
    const screen = make(EventCodes, 'ev-1');
    screen['event'].set(night());
    screen['setBatch']('name', 'Radio giveaway');
    screen['setBatch']('startsAt', '2030-11-15T10:00');

    await screen['saveBatch']();

    expect(asked[0].consequences?.[0]).toMatch(/^They work from .+, for anybody you give them to\.$/);
    expect(organizer['createCodeBatch']).not.toHaveBeenCalled();
  });

  it('names only what a copy carries over, and copies nothing on no', async () => {
    const screen = make(EventHub, 'ev-1');
    screen['event'].set(night());
    screen['copyTitle'].set('Afro Fest II');
    screen['copyStarts'].set('2026-11-20T21:00');

    await screen['duplicate']();

    // EventDuplicator copies the tiers, the banner and the reminders — not
    // the extras, the questions, the codes or the gallery.
    expect(asked[0].body).toContain('the same details, tickets, prices, capacity, banner and reminders');
    expect(asked[0].body).not.toMatch(/extras|questions/);
    expect(asked[0].consequences).toContain(
      'Extras, questions, codes and the gallery are not copied. Add the extras and questions again if the new night needs them.',
    );
    expect(organizer['duplicate']).not.toHaveBeenCalled();
  });

  it('counts this night among the dates a repeat makes, as the server does', async () => {
    const screen = make(EventHub, 'ev-1');
    screen['event'].set(night());
    chosen = 'weekly';
    typed = '6';

    await screen['repeat']();

    // The server's count includes the night that already exists: 6 is five new.
    expect(asked[0].body).toContain('6 dates in all, counting this one: 5 more are added, every week');
    expect(asked[0].confirmLabel).toBe('Add 5 more dates');
    expect(organizer['repeat']).not.toHaveBeenCalled();
  });

  it('refuses a repeat of one date before asking, rather than after the server does', async () => {
    const screen = make(EventHub, 'ev-1');
    screen['event'].set(night());
    chosen = 'weekly';
    typed = '1';

    await screen['repeat']();

    expect(asked).toEqual([]);
    expect(toasts.show).toHaveBeenCalledWith('Repeat it for 2 to 104 dates, counting this one.', 'danger');
    expect(organizer['repeat']).not.toHaveBeenCalled();
  });

  it('holds a cancellation reason to what the server takes, and sends it from the sheet', async () => {
    const screen = make(EventHub, 'ev-1');
    screen['event'].set(night());

    await screen['cancel']();

    const question = asked[0];

    // "Rain" is refused by the API (10 to 500 characters); the button waits.
    expect(question.reason).toMatchObject({ required: true, minLength: 10, maxLength: 500 });
    expect(confirmReady(question, '', 'Rain')).toBe(false);
    expect(confirmReady(question, '', 'The venue has had to close')).toBe(true);
    expect(organizer['cancel']).not.toHaveBeenCalled();

    // Sent from the sheet, so a refusal stays in it with the reason written.
    await question.run?.('The venue has had to close');
    expect(organizer['cancel']).toHaveBeenCalledWith('ev-1', 'The venue has had to close', true);
  });
});
