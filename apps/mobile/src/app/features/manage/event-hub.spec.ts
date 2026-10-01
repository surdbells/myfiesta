import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import type { EventReviewResult, EventReviewState, OrganizerEventDetail } from '@myfiesta/api-types';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Discover } from '../../core/discovery';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { Dialogs, ToastStore, type ConfirmRequest, type MenuOptions } from '../../ui';
import { EventContext } from './event-context';
import { EventHub } from './event-hub';

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

function night(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
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
 * The phone's part in getting a night on sale: nothing goes on sale without
 * myFiesta looking at it first.
 *
 * The same as the console. "Submit for review" replaces "Put it on sale" and
 * asks first, in the sheet, saying the night cannot be changed while it
 * waits; a night waiting can be taken back; the reason it was last sent back
 * stays at the top; and taking a night off sale says before it happens
 * whether putting it back will need another review.
 */
describe('the event screen and the review', () => {
  let shown: OrganizerEventDetail;
  let asked: ConfirmRequest[];
  let yes: boolean;
  let chosen: string | null;
  let menus: MenuOptions[];
  let organizer: {
    summary: ReturnType<typeof vi.fn>;
    series: ReturnType<typeof vi.fn>;
    submitForReview: ReturnType<typeof vi.fn>;
    withdrawFromReview: ReturnType<typeof vi.fn>;
    publish: ReturnType<typeof vi.fn>;
    cancellationPreview: ReturnType<typeof vi.fn>;
    cancel: ReturnType<typeof vi.fn>;
  };
  let decided: ConfirmRequest[];
  let context: { get: ReturnType<typeof vi.fn>; forget: ReturnType<typeof vi.fn>; peek: () => null; remember: () => void };
  let toasts: { show: ReturnType<typeof vi.fn> };

  const answer = (result: Partial<EventReviewResult>) => vi.fn(async () => ({ status: 'draft', outcome: 'already', message: '', ...result }));

  beforeEach(() => {
    shown = night();
    asked = [];
    yes = true;
    chosen = null;
    menus = [];
    decided = [];

    organizer = {
      summary: vi.fn(async () => null),
      series: vi.fn(async () => ({ series: null })),
      submitForReview: answer({
        status: 'in_review',
        outcome: 'in_review',
        message: 'Sent for review. We will email you when it has been looked at — usually within one working day.',
      }),
      withdrawFromReview: answer({ status: 'draft', outcome: 'withdrawn', message: 'Taken back from review. Make your changes, then send it again.' }),
      publish: answer({ status: 'draft', outcome: 'unpublished', message: 'Taken off sale.' }),
      cancellationPreview: vi.fn(async () => ({ ticket_holders: 0, orders_to_refund: 0, refund_total: { amount: 0, currency: 'CAD' } })),
      cancel: vi.fn(async () => ({ notified: 0, refunded: 0, failed: 0 })),
    };
    context = { get: vi.fn(async () => shown), forget: vi.fn(), peek: () => null, remember: () => undefined };
    toasts = { show: vi.fn() };

    // The sheet itself is tested with the kit (dialogs.spec). Here it is the
    // person reading the question: what it says is kept, and saying yes runs
    // the request the way the sheet would.
    const dialogs = {
      confirm: vi.fn(async (request: ConfirmRequest) => {
        asked.push(request);
        if (!yes) return false;
        await request.run?.(undefined);
        return true;
      }),
      menu: vi.fn(async (options: MenuOptions) => {
        menus.push(options);
        return chosen;
      }),
      decide: vi.fn(async (request: ConfirmRequest) => {
        decided.push(request);
        return { confirmed: false, reason: null };
      }),
    };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Organizer, useValue: organizer },
        { provide: EventContext, useValue: context },
        { provide: Dialogs, useValue: dialogs },
        { provide: ToastStore, useValue: toasts },
        { provide: SessionStore, useValue: { can: (permission: string) => permission !== 'money.view' } },
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.test' } },
      ],
    });
  });

  async function open() {
    const fixture = TestBed.createComponent(EventHub);
    fixture.componentRef.setInput('id', 'ev-1');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  function press(fixture: { nativeElement: HTMLElement }, label: string): HTMLButtonElement {
    // By what it says, or — for an icon — by the name it is read out with.
    const found = [...fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')].find(
      (candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim() === label || candidate.getAttribute('aria-label') === label,
    );

    if (!found) throw new Error(`No "${label}" button on the screen`);

    found.click();

    return found;
  }

  async function settle(fixture: { detectChanges(): void; whenStable(): Promise<unknown> }) {
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('offers "Submit for review", and asks first, saying it cannot be changed while it waits', async () => {
    const fixture = await open();

    expect(text(fixture)).toContain('Not on sale yet');
    expect(text(fixture)).toContain('myFiesta looks at every event before it goes on sale');

    shown = night({ status: 'in_review', review: review({ submitted_at: '2026-09-28T12:00:00Z', on_submit: null }) });
    press(fixture, 'Submit for review');
    await settle(fixture);

    expect(asked[0].title).toBe('Send Afro Fest for review?');
    expect(asked[0].consequences?.join(' ')).toContain('While it is being reviewed you cannot change it');
    expect(asked[0].confirmLabel).toBe('Submit for review');
    expect(organizer.submitForReview).toHaveBeenCalledWith('ev-1');
    expect(toasts.show).toHaveBeenCalledWith(expect.stringContaining('Sent for review.'), 'success');
    expect(context.forget).toHaveBeenCalledWith('ev-1');

    // Read again: it is waiting now, and can be taken back.
    expect(text(fixture)).toContain('Waiting for review');
    expect(text(fixture)).toContain('Withdraw from review');
  });

  it('sends nothing when the question is answered no', async () => {
    const fixture = await open();
    yes = false;

    press(fixture, 'Submit for review');
    await settle(fixture);

    expect(asked).toHaveLength(1);
    expect(organizer.submitForReview).not.toHaveBeenCalled();
  });

  it('lists what stops it being sent, and does not let it be sent until it is ready', async () => {
    shown = night({ review: review({ not_ready: ['Add at least one ticket on sale.', 'Say where the event is.'] }) });
    const fixture = await open();

    expect(text(fixture)).toContain('Before it can be sent for review:');
    expect(text(fixture)).toContain('Add at least one ticket on sale.');
    expect(text(fixture)).toContain('Say where the event is.');
    expect(press(fixture, 'Submit for review').disabled).toBe(true);
    expect(asked).toHaveLength(0);
  });

  it('says it goes straight back on sale when nothing has changed since it was approved', async () => {
    shown = night({ review: review({ on_submit: 'publish', approved_at: '2026-09-20T12:00:00Z' }) });
    organizer.submitForReview = answer({
      status: 'published',
      outcome: 'published',
      message: 'Back on sale. Nothing has changed since it was approved, so it did not need another review.',
    });
    const fixture = await open();

    expect(text(fixture)).toContain('Nothing a buyer sees has changed since it was approved, so it goes straight back on sale.');
    press(fixture, 'Put back on sale');
    await settle(fixture);

    expect(asked[0].title).toBe('Put Afro Fest back on sale?');
    expect(asked[0].body).toContain('without another review');
    expect(organizer.submitForReview).toHaveBeenCalledWith('ev-1');
  });

  it('offers to put an approved night waiting for its time on sale now, not back, and says it skips the wait', async () => {
    // A week before the time set: 10am in Toronto on 9 October.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-01T16:00:00Z'));

    try {
      shown = night({ publish_at: '2026-10-09T14:00:00+00:00', review: review({ on_submit: 'publish', approved_at: '2026-10-01T12:00:00Z' }) });
      yes = false;
      const fixture = await open();

      expect(text(fixture)).toContain('It is approved and goes on sale by itself at');
      expect(text(fixture)).not.toContain('Put back on sale');

      press(fixture, 'Put on sale now');
      await settle(fixture);

      expect(asked[0].title).toBe('Put Afro Fest on sale now?');
      expect(asked[0].body).toContain('does not wait for that time');
      expect(organizer.submitForReview).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });

  it('takes a waiting night back after asking, so it can be changed', async () => {
    shown = night({ status: 'in_review', review: review({ submitted_at: '2026-09-28T12:00:00Z', on_submit: null }) });
    const fixture = await open();

    expect(text(fixture)).toContain('It cannot be changed while it waits.');
    shown = night();
    press(fixture, 'Withdraw from review');
    await settle(fixture);

    expect(asked[0].title).toBe('Withdraw Afro Fest from review?');
    expect(organizer.withdrawFromReview).toHaveBeenCalledWith('ev-1');
    expect(text(fixture)).toContain('Submit for review');
  });

  it('keeps the reason it was sent back at the top, word for word, with the history below', async () => {
    const reason = 'The poster is from last year’s event. Upload this year’s, then send it again.';
    shown = night({
      review: review({
        rejection: { reason, at: '2026-09-27T15:00:00Z' },
        history: [
          { action: 'rejected', via: null, reason, at: '2026-09-27T15:00:00Z', by: 'myFiesta' },
          { action: 'submitted', via: null, reason: null, at: '2026-09-27T10:00:00Z', by: 'Ada Okafor' },
        ],
      }),
    });
    const fixture = await open();

    expect(text(fixture)).toContain('myFiesta sent this back');
    expect(text(fixture)).toContain(reason);
    expect(text(fixture)).toContain('Sent back with changes to make');
    expect(text(fixture)).toContain('Ada Okafor');
  });

  it('says before taking a night off sale whether putting it back needs another review', async () => {
    shown = night({ status: 'published', review: review({ on_submit: null, unchanged_since_approval: false }) });
    chosen = 'unpublish';
    const fixture = await open();

    press(fixture, 'Event actions');
    await settle(fixture);

    const offered = menus[0].actions.find((action) => action.key === 'unpublish');
    expect(offered?.hint).toBe('Putting it back needs another review');
    expect(asked[0].title).toBe('Take Afro Fest off sale?');
    expect(asked[0].consequences?.join(' ')).toContain('putting it back on sale will need another review');
    expect(organizer.publish).toHaveBeenCalledWith('ev-1', 'draft');
  });

  it('offers "Submit for review" in the menu rather than "Put it on sale"', async () => {
    const fixture = await open();

    press(fixture, 'Event actions');
    await settle(fixture);

    const labels = menus[0].actions.map((action) => action.label);
    expect(labels).toContain('Submit for review');
    expect(labels).not.toContain('Put it on sale');
  });

  /*
   * While the organization is suspended. A night the suspension took off
   * sale, with tickets sold, read "Draft", sat under "Not on sale yet", and
   * offered "Put back on sale" — which the API refuses with a 403.
   */
  it('says a night the suspension took off sale is off sale, and offers nothing that would be refused', async () => {
    shown = night({ off_sale_by_suspension: true, tickets_issued: 1, review: review({ on_submit: null, suspended: true }) });
    const fixture = await open();

    expect(text(fixture)).toContain('Off sale');
    expect(text(fixture)).toContain('Off sale while you are suspended');
    expect(text(fixture)).toContain('goes back on sale by itself when the suspension is lifted');
    expect(text(fixture)).not.toContain('Draft');
    expect(text(fixture)).not.toContain('Put back on sale');
    expect(text(fixture)).not.toContain('Submit for review');

    press(fixture, 'Event actions');
    await settle(fixture);

    const labels = menus[0].actions.map((action) => action.label);
    expect(labels).not.toContain('Put back on sale');
    expect(labels).not.toContain('Submit for review');
  });

  /*
   * Sharing a night that has no public page. "Share" and "Share the link"
   * were offered on a draft and on a night a suspension held, and handed out
   * an address that answers 404.
   */
  it('offers the link only while there is a public page to open', async () => {
    const draft = await open();

    expect(() => press(draft, 'Share')).toThrow();
    press(draft, 'Event actions');
    await settle(draft);
    expect(menus[0].actions.map((action) => action.label)).not.toContain('Share the link');

    shown = night({ status: 'published' });
    const published = await open();

    press(published, 'Event actions');
    await settle(published);
    expect(menus[1].actions.map((action) => action.label)).toEqual(expect.arrayContaining(['Share the link', 'See the public page']));
    expect(() => press(published, 'Share')).not.toThrow();
  });

  it('says there is nobody to tell when nobody holds a ticket, rather than "0 people"', async () => {
    chosen = 'cancel';
    const fixture = await open();

    press(fixture, 'Event actions');
    await settle(fixture);
    await settle(fixture);

    expect(decided).toHaveLength(1);
    expect(decided[0].body).toBe('Nobody holds a ticket yet, so there is nobody to tell.');
    expect(decided[0].confirmLabel).toBe('Cancel the event');
  });

  /*
   * The menu said "Tells every ticket holder, and can refund them" on a draft
   * nobody could have bought yet.
   */
  it('offers to cancel a night nobody holds a ticket for without promising to tell anybody', async () => {
    const draft = await open();

    press(draft, 'Event actions');
    await settle(draft);
    expect(menus[0].actions.find((action) => action.key === 'cancel')?.hint).toBe('Nobody holds a ticket yet, so nobody to tell');

    shown = night({ status: 'published', tickets_issued: 12 });
    const selling = await open();

    press(selling, 'Event actions');
    await settle(selling);
    expect(menus[1].actions.find((action) => action.key === 'cancel')?.hint).toBe('Tells every ticket holder, and can refund them');
  });

  /*
   * The menu said "nobody to tell or refund" on a night whose one paid order
   * had its ticket up for resale, and the question after it then refunded
   * that order. The count on the night leaves such tickets out; the refunds
   * come from the orders.
   */
  it('leaves refunds to the question when the night counts no tickets but still has paid orders', async () => {
    chosen = 'cancel';
    shown = night({ status: 'published', tickets_issued: 0 });
    organizer.cancellationPreview = vi.fn(async () => ({ ticket_holders: 0, orders_to_refund: 1, refund_total: { amount: 4000, currency: 'CAD' } }));
    const fixture = await open();

    press(fixture, 'Event actions');
    await settle(fixture);
    await settle(fixture);

    const hint = menus[0].actions.find((action) => action.key === 'cancel')?.hint;
    expect(hint).toBe('Nobody holds a ticket yet, so nobody to tell');
    expect(hint).not.toMatch(/refund/i);
    expect(decided[0].consequences).toContain('1 order is refunded, $40.00 in all.');
  });

  it('still counts the people it will tell when somebody holds a ticket', async () => {
    chosen = 'cancel';
    organizer.cancellationPreview = vi.fn(async () => ({ ticket_holders: 3, orders_to_refund: 0, refund_total: { amount: 0, currency: 'CAD' } }));
    const fixture = await open();

    press(fixture, 'Event actions');
    await settle(fixture);
    await settle(fixture);

    expect(decided[0].body).toBe('3 people hold a ticket and will be told.');
    expect(decided[0].confirmLabel).toBe('Cancel and tell them');
  });

  it('keeps their own draft a draft while suspended, with nothing to send it with', async () => {
    shown = night({ review: review({ on_submit: null, suspended: true }) });
    const fixture = await open();

    expect(text(fixture)).toContain('Draft');
    expect(text(fixture)).toContain('Nothing can be sent for review while this organization is suspended.');
    expect(text(fixture)).not.toContain('Submit for review');
  });
});
