import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { EventReviewState, OrganizerEventDetail } from '@myfiesta/api-types';
import { API_BASE_URL, authInterceptor } from '../../core/api';
import { answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { SessionStore } from '../../core/session';
import { EventDetail } from './event-detail';
import { eventStandingLabel, eventStandingTone, eventStatusLabel, eventStatusTone, reviewStepLabel } from './event-status';

const BASE = 'http://api.test/api/organizer/events/ev-1';

function review(overrides: Partial<EventReviewState> = {}): EventReviewState {
  return {
    submitted_at: null,
    approved_at: null,
    on_submit: 'review',
    unchanged_since_approval: false,
    not_ready: [],
    rejection: null,
    history: [],
    ...overrides,
  };
}

function event(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
  return {
    id: 'ev-1',
    slug: 'afro-fest',
    title: 'Afro Fest',
    kind: 'ticketed',
    status: 'draft',
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
    tickets_issued: 0,
    checked_in: 0,
    orders: 0,
    capacity: 200,
    revenue: null,
    views: 0,
    last_sale_at: null,
    poster_url: null,
    review: review(),
    ...overrides,
  };
}

/**
 * The overview's part in getting a night on sale: nothing goes on sale
 * without myFiesta looking at it first.
 *
 * "Submit for review" replaces "Publish", and asks first, saying the event
 * cannot be changed while it waits. An event waiting can be taken back. The
 * reason it was last sent back stays on the event until it is sent again, and
 * the history says who did what and when. Taking an event off sale says
 * before it happens whether putting it back will need another review.
 */
describe('EventDetail: the review', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeAll(() => {
    // jsdom's <dialog> has no showModal(); the question only needs it not to throw.
    const dialog = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
    dialog['showModal'] ??= function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    };
    dialog['close'] ??= function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    };
  });

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: 'ev-1' }) }, parent: null },
        },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);

    // Somebody who may send events, without the money: the overview asks for
    // sales figures only for members who may see them.
    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [
        {
          id: 'org-1',
          name: 'Lagos Nights',
          slug: 'lagos-nights',
          role: 'manager',
          permissions: ['events.view', 'events.edit', 'events.publish', 'events.create'],
        },
      ],
    });
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
    session.clear();
  });

  async function render(shown: OrganizerEventDetail) {
    const fixture = TestBed.createComponent(EventDetail);
    fixture.detectChanges();

    backend.expectOne(BASE).flush(shown);
    backend.expectOne(`${BASE}/reminders`).flush({ data: [] });
    backend.expectOne(`${BASE}/series`).flush({ series: null });
    fixture.detectChanges();
    await settle();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  function button(fixture: { nativeElement: HTMLElement }, label: string): HTMLButtonElement {
    const found = [...fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')].find(
      (candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim() === label,
    );

    if (!found) throw new Error(`No "${label}" button on the page`);

    return found;
  }

  it('offers "Submit for review" rather than "Publish", and asks first, saying it cannot be changed while it waits', async () => {
    const fixture = await render(event());

    expect(text(fixture)).not.toContain('Publish');
    button(fixture, 'Submit for review').click();
    await settle();

    const question = asked();
    expect(question?.title).toBe('Send Afro Fest for review?');
    expect(question?.text).toContain('While it is being reviewed you cannot change it');
    expect(question?.text).toContain('You can withdraw it from review at any time');

    await answer('Submit for review');

    backend.expectOne(`${BASE}/submit`).flush({
      status: 'in_review',
      outcome: 'in_review',
      message: 'Sent for review. We will email you when it has been looked at — usually within one working day.',
    });
    await settle();

    backend.expectOne(BASE).flush(event({ status: 'in_review', review: review({ submitted_at: '2026-09-28T12:00:00Z', on_submit: null }) }));
    await settle();
    fixture.detectChanges();

    expect(text(fixture)).toContain('Sent for review. We will email you');
    expect(text(fixture)).toContain('Waiting for review');
    expect(button(fixture, 'Withdraw from review')).toBeTruthy();
  });

  it('sends nothing when the question is answered no', async () => {
    const fixture = await render(event());

    button(fixture, 'Submit for review').click();
    await settle();
    await answer('Cancel');

    backend.expectNone(`${BASE}/submit`);
    expect(text(fixture)).toContain('Submit for review');
  });

  it('lists what stops it being sent, and does not offer the button until it is ready', async () => {
    const fixture = await render(
      event({ review: review({ not_ready: ['Add at least one ticket on sale.', 'The start date has passed. Change the date.'] }) }),
    );

    expect(text(fixture)).toContain('Before it can be sent:');
    expect(text(fixture)).toContain('Add at least one ticket on sale.');
    expect(text(fixture)).toContain('The start date has passed. Change the date.');
    expect(button(fixture, 'Submit for review').disabled).toBe(true);
  });

  it('says it goes straight back on sale when nothing has changed since it was approved', async () => {
    const fixture = await render(event({ review: review({ on_submit: 'publish', approved_at: '2026-09-20T12:00:00Z' }) }));

    expect(text(fixture)).toContain('Nothing a buyer sees has changed since it was approved, so it goes straight back on sale.');
    button(fixture, 'Put back on sale').click();
    await settle();

    expect(asked()?.title).toBe('Put Afro Fest back on sale?');
    expect(asked()?.text).toContain('without another review');

    await answer('Put back on sale');
    backend.expectOne(`${BASE}/submit`).flush({
      status: 'published',
      outcome: 'published',
      message: 'Back on sale. Nothing has changed since it was approved, so it did not need another review.',
    });
    await settle();
    backend.expectOne(BASE).flush(event({ status: 'published', review: review({ on_submit: null, unchanged_since_approval: true }) }));
    await settle();
    fixture.detectChanges();

    expect(text(fixture)).toContain('Back on sale.');
    expect(button(fixture, 'Take off sale')).toBeTruthy();
  });

  it('says when it was sent for review once, with the zone, and no double full stop', async () => {
    const fixture = await render(event({ status: 'in_review', review: review({ submitted_at: '2026-09-28T10:58:00Z', on_submit: null }) }));

    // 6:58 in the morning in Toronto, said as Toronto says it.
    expect(text(fixture)).toMatch(/Sent .*?6:58\sa\.m\.\s(EDT|GMT-4)\. Somebody at myFiesta/);
    expect(text(fixture)).not.toContain('..');
  });

  it('takes it back from review after asking, so it can be changed', async () => {
    const fixture = await render(event({ status: 'in_review', review: review({ submitted_at: '2026-09-28T12:00:00Z', on_submit: null }) }));

    expect(text(fixture)).toContain('It cannot be changed while it waits.');
    button(fixture, 'Withdraw from review').click();
    await settle();

    expect(asked()?.title).toBe('Withdraw Afro Fest from review?');
    await answer('Withdraw from review');

    backend.expectOne(`${BASE}/withdraw`).flush({
      status: 'draft',
      outcome: 'withdrawn',
      message: 'Taken back from review. Make your changes, then send it again.',
    });
    await settle();
    backend.expectOne(BASE).flush(event());
    await settle();
    fixture.detectChanges();

    expect(text(fixture)).toContain('Taken back from review.');
    expect(button(fixture, 'Submit for review')).toBeTruthy();
  });

  it('keeps the reason it was sent back on the event, word for word, and the history below', async () => {
    const reason = 'The poster is from last year’s event. Upload this year’s, then send it again.';
    const fixture = await render(
      event({
        review: review({
          rejection: { reason, at: '2026-09-27T15:00:00Z' },
          history: [
            { action: 'rejected', via: null, reason, at: '2026-09-27T15:00:00Z', by: 'myFiesta' },
            { action: 'submitted', via: null, reason: null, at: '2026-09-27T10:00:00Z', by: 'Ada Okafor' },
          ],
        }),
      }),
    );

    expect(text(fixture)).toContain('myFiesta sent this back');
    expect(text(fixture)).toContain(reason);
    expect(text(fixture)).toContain('Review history');
    expect(text(fixture)).toContain('Sent back with changes to make');
    expect(text(fixture)).toContain('Sent for review');
    expect(text(fixture)).toContain('by Ada Okafor');
  });

  it('says before taking it off sale whether putting it back will need another review', async () => {
    const changed = await render(event({ status: 'published', review: review({ on_submit: null, unchanged_since_approval: false }) }));

    button(changed, 'Take off sale').click();
    await settle();

    expect(asked()?.title).toBe('Take Afro Fest off sale?');
    expect(asked()?.text).toContain('putting it back on sale will need another review');

    await answer('Take off sale');
    backend.expectOne(`${BASE}/publish`).flush({ status: 'draft', outcome: 'unpublished', message: 'Taken off sale.' });
    await settle();
    backend.expectOne(BASE).flush(event());
    await settle();

    forgetDialogs();

    const unchanged = await render(event({ status: 'published', review: review({ on_submit: null, unchanged_since_approval: true }) }));
    button(unchanged, 'Take off sale').click();
    await settle();

    expect(asked()?.text).toContain('you can put it straight back on sale');
    await answer('Cancel');
    backend.expectNone(`${BASE}/publish`);
  });
});

describe('event status words', () => {
  it('reads the same as the admin and the phone', () => {
    expect(['draft', 'in_review', 'published', 'cancelled'].map(eventStatusLabel)).toEqual([
      'Draft',
      'In review',
      'On sale',
      'Cancelled',
    ]);
    expect(eventStatusTone('in_review')).toBe('brand');
    expect(eventStatusTone('draft')).toBe('warning');
  });

  it('names each step of the review, and how an approval came about', () => {
    const at = '2026-09-27T10:00:00Z';

    expect(reviewStepLabel({ action: 'approved', via: 'review', reason: null, at, by: 'myFiesta' })).toBe('Approved and put on sale');
    expect(reviewStepLabel({ action: 'approved', via: 'series', reason: null, at, by: null })).toBe(
      'On sale as the next date of an approved series',
    );
    expect(reviewStepLabel({ action: 'withdrawn', via: null, reason: null, at, by: 'Ada' })).toBe('Taken back from review');
  });
});

/**
 * A night that is over, and a night's figures when there is one of a thing.
 *
 * Afrobeats Rooftop, weeks gone, said "On sale" in its header and offered to
 * take it off sale; its totals read "1 orders · 2 tickets".
 */
describe('EventDetail: a finished night, and counts of one', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: 'ev-1' }) }, parent: null },
        },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);

    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [
        {
          id: 'org-1',
          name: 'Lagos Nights',
          slug: 'lagos-nights',
          role: 'owner',
          permissions: ['events.view', 'events.edit', 'events.publish', 'money.view'],
        },
      ],
    });
  });

  afterEach(() => {
    backend.verify();
    session.clear();
  });

  async function render(shown: OrganizerEventDetail, orders: number, tickets: number) {
    const fixture = TestBed.createComponent(EventDetail);
    fixture.detectChanges();

    backend.expectOne(BASE).flush(shown);
    backend.expectOne(`${BASE}/reminders`).flush({ data: [] });
    backend.expectOne(`${BASE}/series`).flush({ series: null });
    const cad = (amount: number) => ({ amount, currency: 'CAD' as const });
    backend.expectOne(`${BASE}/summary`).flush({
      currency: 'CAD',
      gross: cad(5_000),
      discounts: cad(0),
      tax: cad(0),
      service_charge: cad(0),
      refunds: cad(0),
      net: cad(5_000),
      orders,
      tickets_issued: tickets,
      checked_in: 0,
    });
    fixture.detectChanges();
    // The sales breakdown below the totals is its own screen's business.
    backend.match(`${BASE}/sales`);
    await settle();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  it('offers nothing to take off sale once the night is over', async () => {
    const fixture = await render(event({ status: 'published', sales_ended: true }), 1, 2);

    expect(text(fixture)).not.toContain('Take off sale');
  });

  it('still offers it while the night is on sale', async () => {
    const fixture = await render(event({ status: 'published', sales_ended: false }), 3, 5);

    expect(text(fixture)).toContain('Take off sale');
    expect(text(fixture)).toContain('3 orders · 5 tickets · 0 arrived');
  });

  it('says one order as one order', async () => {
    const fixture = await render(event({ status: 'published', sales_ended: true }), 1, 1);

    expect(text(fixture)).toContain('1 order · 1 ticket · 0 arrived');
  });
});

describe('what a finished night is called', () => {
  it('is "Over", not "On sale", once the server says its sales have ended', () => {
    expect(eventStandingLabel(event({ status: 'published', sales_ended: true }))).toBe('Over');
    expect(eventStandingTone(event({ status: 'published', sales_ended: true }))).toBe('neutral');
    expect(eventStandingLabel(event({ status: 'published', sales_ended: false }))).toBe('On sale');
    // A copy saved before the server said reads as it did.
    expect(eventStandingLabel(event({ status: 'published' }))).toBe('On sale');
    expect(eventStandingLabel(event({ status: 'cancelled', sales_ended: true }))).toBe('Cancelled');
  });
});
