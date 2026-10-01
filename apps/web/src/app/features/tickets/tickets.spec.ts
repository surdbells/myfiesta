import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { ConfirmDialog, type ConfirmRequest } from '@myfiesta/ui';
import { API_BASE_URL } from '../../core/api-base';
import type { TicketAccess } from '../../core/api.types';
import { TransferPart } from './parts/transfer-part';
import { Tickets } from './tickets';

const BASE = 'https://api.myfiesta.test/api/tickets/tok-1';

function access(listed = false): TicketAccess {
  return {
    reference: 'MF-7Q2K',
    status: 'paid',
    buyer_name: 'Ada Okafor',
    sent: false,
    event: {
      slug: 'afro',
      title: 'Afro Night',
      starts_at: '2026-10-03T22:00:00Z',
      timezone: 'America/Toronto',
      venue: null,
      address: null,
      city: 'Toronto',
      min_age: null,
      calendar: { ics_url: '', google_url: '' },
      id_required: false,
      organizer: 'Lagos Nights',
    },
    tickets: [
      {
        id: 't-1',
        code: null,
        type: 'General',
        holder: 'Ada Okafor',
        status: 'valid',
        admits: 1,
        admitted: 0,
        qr: null,
        return: { listed, refusal: null },
        perks: [],
        share_link: null,
        transferable: null,
      },
    ],
    extras: [],
    receipt: null as unknown as TicketAccess['receipt'],
  };
}

/**
 * Giving a ticket back, and keeping it after all, each ask first.
 *
 * The question says what people get wrong — the money comes when somebody
 * takes the place, not now — and saying no leaves the ticket as it was.
 */
describe('Tickets: giving one back', () => {
  let http: HttpTestingController;
  let asked: ConfirmRequest[];
  let yes: boolean;

  beforeEach(() => {
    asked = [];
    yes = false;

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({ token: 'tok-1' }) } } },
        { provide: ConfirmDialog, useValue: { confirm: async (request: ConfirmRequest) => (asked.push(request), yes) } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function open(listed = false): Tickets {
    const page = TestBed.createComponent(Tickets).componentInstance;
    http.expectOne(BASE).flush(access(listed));

    return page;
  }

  it('says when the money comes before giving it back, and gives nothing back on no', async () => {
    const page = open();

    await page.giveBack('t-1');

    expect(asked[0].title).toBe('Give back your General ticket for Afro Night?');
    expect(asked[0].body).toContain('you get back what you paid once somebody takes the place');
    expect(asked[0].tone).toBe('danger');
    http.expectNone(`${BASE}/resale/t-1`);
    expect(page.busy()).toBe(false);
  });

  it('gives it back once it is confirmed', async () => {
    const page = open();
    yes = true;

    await page.giveBack('t-1');

    const request = http.expectOne(`${BASE}/resale/t-1`);
    expect(request.request.method).toBe('POST');
    request.flush({ message: 'Listed. You are paid back when somebody takes it.', access: access(true) });
    expect(page.notice()).toBe('Listed. You are paid back when somebody takes it.');
  });

  it('asks before keeping it after all, and keeps nothing on no', async () => {
    const page = open(true);

    await page.keep('t-1');

    expect(asked[0].title).toBe('Keep your General ticket for Afro Night?');
    http.expectNone(`${BASE}/resale/t-1`);
  });

  it('says only the ticket being kept is working, not every ticket on the order', async () => {
    const fixture = TestBed.createComponent(Tickets);
    const page = fixture.componentInstance;
    const order = access();
    const [ticket] = order.tickets;
    order.tickets = [
      { ...ticket, id: 't-1', return: { listed: true, refusal: null } },
      { ...ticket, id: 't-2', return: { listed: false, refusal: null } },
    ];
    http.expectOne(BASE).flush(order);
    yes = true;

    await page.keep('t-1');
    fixture.detectChanges();

    const labels = [...(fixture.nativeElement as HTMLElement).querySelectorAll('button')].map((button) =>
      (button.textContent ?? '').replace(/\s+/g, ' ').trim(),
    );

    // Ticket A is being kept. Ticket B is untouched, and its link must not
    // read as though it were being handed back.
    expect(labels).toContain('Keeping it…');
    expect(labels).toContain("Can't go? Give this ticket back");
    expect(labels).not.toContain('Giving it back…');

    const request = http.expectOne(`${BASE}/resale/t-1`);
    expect(request.request.method).toBe('DELETE');
    request.flush({ message: 'Kept. It works at the door again.', access: access() });
    expect(page.busy()).toBe(false);
  });
});

/**
 * A ticket somebody was sent, on the link that came with it: that ticket and
 * nothing else of the order's.
 */
describe('Tickets: one somebody was sent', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({ token: 'tok-1' }) } } },
        { provide: ConfirmDialog, useValue: { confirm: async () => false } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function sent(tickets = true): TicketAccess {
    const page = access();

    return {
      ...page,
      reference: null,
      status: null,
      buyer_name: null,
      sent: true,
      receipt: null,
      tickets: tickets ? page.tickets.map((ticket) => ({ ...ticket, transferable: true })) : [],
    };
  }

  function draw(page: TicketAccess): string {
    const fixture = TestBed.createComponent(Tickets);
    http.expectOne(BASE).flush(page);
    fixture.detectChanges();

    return ((fixture.nativeElement as HTMLElement).textContent ?? '').replace(/\s+/g, ' ');
  }

  it('shows the ticket with no receipt and no order number, and says it was sent', () => {
    const text = draw(sent());

    expect(text).toContain('This ticket was sent to you.');
    expect(text).toContain('Send to someone');
    expect(text).not.toContain('Order');
    expect(text).not.toContain('Receipt');
  });

  it('says the link no longer opens a ticket once it has gone on', () => {
    const text = draw(sent(false));

    expect(text).toContain('This link no longer opens a ticket.');
    expect(text).not.toContain('There are no live tickets on this order.');
  });

  it('keeps the order number on the order page', () => {
    expect(draw(access())).toContain('MF-7Q2K');
  });
});

/**
 * Sending a ticket on, as the page takes the answer: said where the page
 * says everything, and drawn again without the ticket that went. A page that
 * kept showing it would keep showing a code that no longer opens the door.
 */
describe('Tickets: sending one on', () => {
  let http: HttpTestingController;
  let asked: ConfirmRequest[];

  beforeEach(() => {
    asked = [];

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({ token: 'tok-1' }) } } },
        { provide: ConfirmDialog, useValue: { confirm: async (request: ConfirmRequest) => (asked.push(request), true) } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function text(element: HTMLElement): string {
    return (element.textContent ?? '').replace(/\s+/g, ' ');
  }

  it('says it was sent and stops showing the ticket and its code', async () => {
    const page = access();
    page.tickets = page.tickets.map((ticket) => ({ ...ticket, code: 'K7Q2-M4XP', transferable: true }));

    const fixture = TestBed.createComponent(Tickets);
    http.expectOne(BASE).flush(page);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(text(element)).toContain('K7Q2-M4XP');

    (element.querySelector('button.send') as HTMLButtonElement).click();
    fixture.detectChanges();
    const part = fixture.debugElement.query(By.directive(TransferPart)).componentInstance as TransferPart;
    part.name.set('Chioma Eze');
    part.email.set('chioma@example.com');
    await part.send();

    expect(asked.length).toBe(1);
    const request = http.expectOne(`${BASE}/transfer/t-1`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ email: 'chioma@example.com', name: 'Chioma Eze' });
    request.flush({
      message: 'Sent to chioma@example.com. They have an email with the ticket and a link to it.',
      access: { ...access(), tickets: [] },
    });
    fixture.detectChanges();

    expect(element.querySelector('.notice')?.textContent).toContain('Sent to chioma@example.com.');
    expect(text(element)).not.toContain('K7Q2-M4XP');
    expect(fixture.componentInstance.order()?.tickets.some((ticket) => ticket.id === 't-1')).toBe(false);
  });
});
