import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { OrganizerEventDetail, SoldOrder } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../core/api';
import { whenCodeWorks } from '@myfiesta/shared/code-window';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { CodeBatches } from './code-batches';
import { EventCodes } from './event-codes';
import { EventDetail } from './event-detail';
import { EventEdit } from './event-edit';
import { EventMessages } from './event-messages';
import { EventOrders } from './event-orders';
import { EventTickets } from './event-tickets';

const BASE = 'http://api.test/api/organizer/events/ev-1';

const cad = (amount: number) => ({ amount, currency: 'CAD' as const });

function event(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
  return {
    id: 'ev-1',
    slug: 'afro-fest',
    title: 'Afro Fest',
    kind: 'ticketed',
    status: 'published',
    starts_at: '2026-11-14T01:00:00Z',
    ends_at: null,
    timezone: 'America/Toronto',
    city: 'Toronto',
    subdivision: 'ON',
    country: 'CA',
    currency: 'CAD',
    description: '<p>Afrobeats until late.</p>',
    category: null,
    min_age: null,
    id_required: false,
    resale_enabled: false,
    resale_closes_hours: 24,
    tickets_issued: 12,
    checked_in: 0,
    orders: 6,
    capacity: 200,
    revenue: null,
    views: 0,
    last_sale_at: null,
    poster_url: null,
    review: {
      submitted_at: null,
      approved_at: null,
      on_submit: 'review',
      unchanged_since_approval: true,
      not_ready: [],
      rejection: null,
      history: [],
    },
    ...overrides,
  };
}

function order(): SoldOrder {
  return {
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
}

const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 30, total: data.length } });

/**
 * The event screens' actions, each asked about first.
 *
 * A refund, an email to every ticket holder, an edit to a night on sale and
 * a new ticket type are all in front of buyers, or in their bank, the moment
 * they land. Each names what it will do, and saying no sends nothing.
 */
describe('Event screens: asking first', () => {
  let backend: HttpTestingController;

  beforeAll(allowDialogs);

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: 'ev-1' }) }, parent: null },
        },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
  });

  /** What the codes screen asks for once it is drawn, answered with nothing in it. */
  function flushCodesScreen(): void {
    backend.match(BASE).forEach((request) => request.flush(event()));
    backend.match(`${BASE}/ticket-types`).forEach((request) => request.flush({ data: [] }));
    backend.match((request) => request.urlWithParams.startsWith(`${BASE}/codes`)).forEach((request) => request.flush(page([])));
    backend.match(`${BASE}/code-batches`).forEach((request) => request.flush({ data: [] }));
  }

  it('names the money, who gets it and what stops working before a refund, and refunds nothing on no', async () => {
    const screen = TestBed.createComponent(EventOrders).componentInstance;
    backend.expectOne((request) => request.url === `${BASE}/orders`).flush(page([order()]));

    screen.open(order());
    void screen.submit(order());
    await settle();

    expect(asked()?.title).toBe('Refund 2 tickets on MF-7Q2K?');
    expect(asked()?.text).toContain('$80.00 goes back to chidi@example.test');
    expect(asked()?.text).toContain('The tickets stop working at the door straight away.');
    expect(asked()?.buttons).toEqual(['Cancel', 'Refund 2 tickets']);

    await answer('Cancel');

    backend.expectNone(`${BASE}/orders/ord-1/refunds`);
    expect(screen.working()).toBe(false);
  });

  it('refunds once it is confirmed', async () => {
    const screen = TestBed.createComponent(EventOrders).componentInstance;
    backend.expectOne((request) => request.url === `${BASE}/orders`).flush(page([order()]));

    screen.open(order());
    void screen.submit(order());
    await settle();
    await answer('Refund 2 tickets');

    backend.expectOne(`${BASE}/orders/ord-1/refunds`).flush({
      id: 'r-1',
      status: 'succeeded',
      amount: cad(8_000),
      tax: cad(0),
      reason: null,
      ticket_ids: ['t-1', 't-2'],
      created_at: '2026-10-02T10:00:00Z',
    });
    backend.expectOne((request) => request.url === `${BASE}/orders`).flush(page([]));
  });

  it('says how many a message reaches before it goes, and sends nothing on no', async () => {
    const screen = TestBed.createComponent(EventMessages).componentInstance;
    backend
      .expectOne((request) => request.url === `${BASE}/messages`)
      .flush({ ...page([]), audience: { holders: 40, reachable: 37 } });

    screen.subject.set('Doors at 9');
    screen.body.set('Come early: the line is long after ten.');

    void screen.submit();
    await settle();

    expect(asked()?.title).toBe('Send “Doors at 9” to 37 people?');
    expect(asked()?.text).toContain('cannot be called back');
    expect(asked()?.text).toContain('3 who asked not to hear from you are left out.');

    await answer('Cancel');

    backend.expectNone((request) => request.method === 'POST');
    expect(screen.subject()).toBe('Doors at 9');
  });

  it('says an edit to a night on sale shows straight away, and saves nothing on no', async () => {
    const screen = TestBed.createComponent(EventEdit).componentInstance;
    backend.expectOne('http://api.test/api/event-categories').flush({ data: [] });
    backend.expectOne(BASE).flush(event());

    screen.form.set({ ...screen.form(), title: 'Afro Fest: Lagos Edition' });

    void screen.submit();
    await settle();

    expect(asked()?.title).toBe('Save the changes to Afro Fest: Lagos Edition?');
    expect(asked()?.text).toContain('It is on sale: the event page shows the changes straight away');

    await answer('Cancel');

    backend.expectNone((request) => request.method === 'PATCH');
    expect(screen.saving()).toBe(false);
  });

  it('names a new ticket type and its price before adding it, and adds nothing on no', async () => {
    const screen = TestBed.createComponent(EventTickets).componentInstance;
    backend.expectOne((request) => request.url === `${BASE}/ticket-types`).flush({ data: [] });
    backend.expectOne(BASE).flush(event());

    screen.openNew();
    screen.update('name', 'Early bird');
    screen.update('price', '25');
    screen.update('quantity', 50);

    void screen.save();
    await settle();

    expect(asked()?.title).toBe('Add Early bird at $25.00?');
    expect(asked()?.text).toContain('50 in all');
    expect(asked()?.text).toContain('buyers see it on the event page straight away');
    expect(asked()?.buttons).toEqual(['Cancel', 'Add the ticket type']);

    await answer('Cancel');

    backend.expectNone((request) => request.method === 'POST');
    expect(screen.formOpen()).toBe(true);

    // The waitlist under the tiers asks for itself once the screen is drawn.
    backend.match(`${BASE}/waitlist`).forEach((request) => request.flush(null));
  });

  it('says a code with a From time works from then, not as soon as it is made', async () => {
    const screen = TestBed.createComponent(EventCodes).componentInstance;

    screen.form.set({ ...screen.form(), code: 'presale', discount_value: '10', starts_at: '2030-11-15T10:00' });

    void screen.submit();
    await settle();

    // Posted today on the word of the question, it would be refused at
    // checkout until the Friday it was set to start.
    expect(asked()?.text).toMatch(/It works from .+, until you turn it off, for anybody who has it\./);
    expect(asked()?.text).not.toContain('as soon as it is made');

    await answer('Cancel');

    backend.expectNone((request) => request.method === 'POST');
    flushCodesScreen();
  });

  it('says a code with no From works as soon as it is made, until its Until', async () => {
    const screen = TestBed.createComponent(EventCodes).componentInstance;

    screen.form.set({ ...screen.form(), code: 'early', discount_value: '10', ends_at: '2030-11-15T10:00' });

    void screen.submit();
    await settle();

    expect(asked()?.text).toMatch(/It works as soon as it is made, until .+, for anybody who has it\./);
    expect(asked()?.text).not.toContain('until you turn it off');

    await answer('Cancel');

    flushCodesScreen();
  });

  it('says a batch with a From time works from then', async () => {
    const fixture = TestBed.createComponent(CodeBatches);
    fixture.componentRef.setInput('eventId', 'ev-1');
    const screen = fixture.componentInstance;

    screen.form.set({ ...screen.form(), name: 'Radio giveaway', starts_at: '2030-11-15T10:00' });

    void screen.create();
    await settle();

    expect(asked()?.text).toMatch(/They work from .+, for anybody you give them to\./);
    expect(asked()?.text).not.toContain('as soon as they are made');

    await answer('Cancel');

    backend.expectNone((request) => request.method === 'POST');
    flushCodesScreen();
  });

  it('counts this date among the dates a repeat makes, as the server does', async () => {
    const screen = TestBed.createComponent(EventDetail).componentInstance;
    backend.expectOne(BASE).flush(event());
    backend.expectOne(`${BASE}/reminders`).flush({ data: [] });
    backend.expectOne(`${BASE}/series`).flush({ series: null });

    screen.repeatCount.set('8');

    void screen.makeRepeating();
    await settle();

    // "For 8 dates" is the series with this night in it: seven new events.
    expect(asked()?.text).toContain('8 dates in all, counting this one: 7 more are added, every week');
    expect(asked()?.buttons).toEqual(['Cancel', 'Add 7 more dates']);

    await answer('Cancel');

    backend.expectNone((request) => request.method === 'POST');
  });

  it('refuses a repeat of one date before asking, rather than after the server does', async () => {
    const screen = TestBed.createComponent(EventDetail).componentInstance;
    backend.expectOne(BASE).flush(event());
    backend.expectOne(`${BASE}/reminders`).flush({ data: [] });
    backend.expectOne(`${BASE}/series`).flush({ series: null });

    screen.repeatCount.set('1');

    await screen.makeRepeating();
    await settle();

    expect(asked()).toBeNull();
    expect(screen.error()).toBe('Repeat it for 2 to 104 dates, counting this one.');
    backend.expectNone((request) => request.method === 'POST');
  });
});

describe('When a code works, in words', () => {
  const format = (iso: string) => iso.slice(0, 10);
  const now = new Date('2026-09-28T12:00:00Z');

  it('says "as soon as it is made" only when there is no From still to come', () => {
    expect(whenCodeWorks({ startsAt: null, endsAt: null, format, now })).toBe(
      'It works as soon as it is made, until you turn it off, for anybody who has it.',
    );
    expect(whenCodeWorks({ startsAt: '2026-09-01T00:00:00Z', endsAt: null, format, now })).toBe(
      'It works as soon as it is made, until you turn it off, for anybody who has it.',
    );
    expect(whenCodeWorks({ startsAt: '2026-10-02T14:00:00Z', endsAt: '2026-10-04T14:00:00Z', format, now })).toBe(
      'It works from 2026-10-02, until 2026-10-04, for anybody who has it.',
    );
  });

  it('says "straight away" for a code being changed, and names a batch as they', () => {
    expect(whenCodeWorks({ startsAt: null, endsAt: null, format, now, editing: true })).toBe(
      'It works straight away, until you turn it off, for anybody who has it.',
    );
    expect(whenCodeWorks({ startsAt: null, endsAt: null, format, now, plural: true })).toBe(
      'They work as soon as they are made, for anybody you give them to.',
    );
  });

  it('says a code whose Until has passed will not work, rather than promising it', () => {
    expect(whenCodeWorks({ startsAt: null, endsAt: '2026-09-27T12:00:00Z', format, now })).toBe(
      'It will not work: the Until time, 2026-09-27, has already passed.',
    );
  });
});
