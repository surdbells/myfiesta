import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import type { EventSummary, OrganizerEventDetail, SoldOrder } from '@myfiesta/api-types';
import { Chrome } from '../../core/chrome';
import { refundEstimate } from './event-orders';
import { unaccounted } from './event-sales';
import { bodyOf, draftOf, isFormatted, plainText } from './event-form';
import { eventStatusLabel, lockedForReview, reviewStepLabel } from './event-review';

/**
 * The arithmetic and the conversions behind the organizer screens.
 *
 * Each of these is a place where a screen can look right and be wrong: a
 * start time an hour off for somebody in another country, a refund button
 * promising a figure the server will not pay, a ledger whose lines do not add
 * up to its total, a description whose formatting is quietly dropped.
 */

const event = (over: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail => ({
  id: 'e1',
  slug: 'afro-fest',
  title: 'Afro Fest',
  kind: 'ticketed',
  status: 'published',
  starts_at: '2026-10-04T02:00:00Z',
  timezone: 'America/Toronto',
  city: 'Toronto',
  currency: 'CAD',
  tickets_issued: 0,
  checked_in: 0,
  orders: 0,
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

describe('the event form', () => {
  it('shows and saves times on the event’s clock, not the phone’s', () => {
    // 02:00 UTC is 10pm the evening before in Toronto.
    const draft = draftOf(event());

    expect(draft.startsAt).toBe('2026-10-03T22:00');
    expect(bodyOf(draft)['starts_at']).toBe(new Date('2026-10-04T02:00:00Z').toISOString());
  });

  it('keeps the same wall clock when the zone is changed, so the instant moves', () => {
    const draft = { ...draftOf(event()), timezone: 'Africa/Lagos' };

    // 10pm in Lagos is 21:00 UTC — five hours earlier than 10pm in Toronto.
    expect(bodyOf(draft)['starts_at']).toBe(new Date('2026-10-03T21:00:00Z').toISOString());
  });

  it('only sends a province for Canada', () => {
    const lagos = { ...draftOf(event()), country: 'NG', subdivision: 'ON' };

    expect(bodyOf(lagos)['subdivision']).toBeNull();
    expect(bodyOf(draftOf(event()))['subdivision']).toBe('ON');
  });

  it('reads a description as paragraphs of words', () => {
    expect(plainText('<p>Doors at 9.</p><p>Dress <strong>sharp</strong>.</p>')).toBe('Doors at 9.\n\nDress sharp.');
    expect(plainText('<ul><li>ID</li><li>Ticket</li></ul>')).toBe('• ID\n\n• Ticket');
    expect(plainText(null)).toBe('');
  });

  it('knows when a plain text box would lose formatting', () => {
    expect(isFormatted('<p>Just words.</p><p>More.<br>Still.</p>')).toBe(false);
    expect(isFormatted('<p>Bring <strong>ID</strong>.</p>')).toBe(true);
    expect(isFormatted('<p>See <a href="https://x.test">the map</a>.</p>')).toBe(true);
    expect(isFormatted(null)).toBe(false);
  });
});

const order = (tickets: boolean[], refundable = 9000): SoldOrder => ({
  id: 'o1',
  reference: 'ABC123',
  buyer_name: 'Bisi Ade',
  buyer_email: 'bisi@example.com',
  status: 'paid',
  paid_at: '2026-09-01T12:00:00Z',
  currency: 'CAD',
  total: { amount: 9000, currency: 'CAD' },
  refunded: { amount: 9000 - refundable, currency: 'CAD' },
  refundable: { amount: refundable, currency: 'CAD' },
  tickets: tickets.map((r, i) => ({ id: `t${i}`, holder_name: null, ticket_type_name: 'General', status: r ? 'valid' : 'refunded', refundable: r })),
});

describe('a refund’s estimate', () => {
  it('is exactly what is left when every ticket still refundable is picked', () => {
    // One of the three already went back; the other two are what remains.
    expect(refundEstimate(order([true, true, false], 6000), 2)).toEqual({ amount: { amount: 6000, currency: 'CAD' }, exact: true });
  });

  it('is an even share, marked as not exact, for some of them', () => {
    expect(refundEstimate(order([true, true, true]), 1)).toEqual({ amount: { amount: 3000, currency: 'CAD' }, exact: false });
  });

  it('is nothing when nothing is picked', () => {
    expect(refundEstimate(order([true, true]), 0).amount.amount).toBe(0);
  });
});

describe('the sales ledger', () => {
  const money = (amount: number) => ({ amount, currency: 'CAD' as const });

  it('names what the lines leave out, so the column adds up', () => {
    // A door sale: sold 56.50 with 6.50 tax, all of it taken in cash — owed nothing.
    const s: EventSummary = {
      currency: 'CAD',
      gross: money(5650),
      discounts: money(0),
      tax: money(650),
      service_charge: money(0),
      refunds: money(0),
      net: money(0),
      orders: 1,
      tickets_issued: 1,
      checked_in: 0,
    };

    expect(unaccounted(s)).toBe(-5000);
    expect(s.gross.amount - s.tax.amount + unaccounted(s)).toBe(s.net.amount);
  });

  it('is nothing when every line is accounted for', () => {
    const s: EventSummary = {
      currency: 'CAD',
      gross: money(10000),
      discounts: money(1000),
      tax: money(1170),
      service_charge: money(400),
      refunds: money(500),
      net: money(7330),
      orders: 3,
      tickets_issued: 4,
      checked_in: 0,
    };

    expect(unaccounted(s)).toBe(0);
  });
});

describe('an event waiting for review', () => {
  it('is locked only while it waits', () => {
    expect(lockedForReview(event({ status: 'in_review' }))).toBe(true);
    expect(lockedForReview(event({ status: 'draft' }))).toBe(false);
    expect(lockedForReview(event({ status: 'published' }))).toBe(false);
    expect(lockedForReview(null)).toBe(false);
  });

  it('is called the same thing as on the console and in the admin', () => {
    expect(eventStatusLabel('draft')).toBe('Draft');
    expect(eventStatusLabel('in_review')).toBe('In review');
    expect(eventStatusLabel('published')).toBe('On sale');
    expect(eventStatusLabel('cancelled')).toBe('Cancelled');
  });

  it('reads its history in words, saying how an approval came about', () => {
    const step = { reason: null, at: '2026-09-01T12:00:00Z', by: 'myFiesta' };

    expect(reviewStepLabel({ ...step, action: 'submitted', via: null })).toBe('Sent for review');
    expect(reviewStepLabel({ ...step, action: 'rejected', via: null, reason: 'Upload this year’s poster.' })).toBe(
      'Sent back with changes to make',
    );
    expect(reviewStepLabel({ ...step, action: 'approved', via: 'review' })).toBe('Approved and put on sale');
    expect(reviewStepLabel({ ...step, action: 'approved', via: 'series' })).toBe('On sale as the next date of an approved series');
  });
});

describe('the pinned footer', () => {
  it('belongs to the screen that reported it, through a transition', () => {
    const chrome = TestBed.inject(Chrome);
    const leaving = {};
    const arriving = {};

    chrome.setFooter(leaving, 80);
    chrome.setFooter(arriving, 96);

    // The old screen is destroyed after the new one has drawn. It must not
    // zero the height the new one just reported.
    chrome.clearFooter(leaving);
    expect(chrome.footerHeight()).toBe(96);

    chrome.clearFooter(arriving);
    expect(chrome.footerHeight()).toBe(0);
  });
});
