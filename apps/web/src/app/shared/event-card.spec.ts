import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EventSummary } from '../core/api.types';
import { formatMoney } from '../core/money';
import { AvailabilityBadge } from './availability-badge';
import { EventCard } from './event-card';

function summary(overrides: Partial<EventSummary> = {}): EventSummary {
  return {
    slug: 'afrobeats-rooftop',
    title: 'Afrobeats Rooftop',
    starts_at: '2026-10-02T01:00:00Z',
    timezone: 'America/Toronto',
    city: 'Toronto',
    country: 'CA',
    currency: 'CAD',
    category: 'Nightlife',
    poster_url: null,
    organizer: { name: 'Lagos Nights', slug: 'lagos-nights' },
    from_price: { amount: 2500, currency: 'CAD' },
    is_sold_out: false,
    availability: { state: 'available', left: null },
    waitlist: false,
    ...overrides,
  } as EventSummary;
}

/**
 * A card says two things before anybody reads the title: whether it is going
 * fast, and what the cheapest way in costs. Both are the API's — the badge
 * counted the way checkout counts — and a card never names an exact number.
 */
describe('EventCard', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter([])] });
  });

  function draw(event: EventSummary, inputs: { past?: boolean; wide?: boolean } = {}): HTMLElement {
    const fixture = TestBed.createComponent(EventCard);
    fixture.componentRef.setInput('event', event);
    if (inputs.past !== undefined) fixture.componentRef.setInput('past', inputs.past);
    if (inputs.wide !== undefined) fixture.componentRef.setInput('wide', inputs.wide);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('says "From" the cheapest price, and nothing about plenty left', () => {
    const card = draw(summary());

    expect(card.textContent).toContain(`From ${formatMoney({ amount: 2500, currency: 'CAD' })}`);
    expect(card.querySelector('[data-tone]')).toBeNull();
    expect(card.textContent).toContain('Get tickets');
  });

  it('badges a night going fast without naming the count', () => {
    const card = draw(summary({ availability: { state: 'almost_sold_out', left: 3 } }));

    expect(card.querySelector('[data-tone="scarce"]')?.textContent?.trim()).toBe('Almost sold out');
    expect(card.textContent).not.toContain('3 left');
  });

  it('says sold out, and leads to the waitlist while it is taking names', () => {
    const waiting = draw(summary({ is_sold_out: true, availability: { state: 'sold_out', left: null }, waitlist: true }));

    expect(waiting.querySelector('[data-tone="sold"]')?.textContent?.trim()).toBe('Sold out');
    expect(waiting.textContent).toContain('Join waitlist');
    expect(waiting.textContent).not.toContain('From');

    const closed = draw(summary({ is_sold_out: true, availability: { state: 'sold_out', left: null }, waitlist: false }));
    expect(closed.textContent).not.toContain('Join waitlist');
  });

  it('says sales closed, not sold out, for a night that stopped selling with places left', () => {
    const card = draw(summary({ is_sold_out: false, availability: { state: 'closed', left: null }, waitlist: false }));

    expect(card.querySelector('[data-tone="sold"]')?.textContent?.trim()).toBe('Sales closed');
    expect(card.textContent).not.toContain('Sold out');
    expect(card.textContent).not.toContain('From');
    expect(card.textContent).not.toContain('Get tickets');
    expect(card.textContent).not.toContain('Join waitlist');
  });

  it('says free rather than $0.00', () => {
    expect(draw(summary({ from_price: { amount: 0, currency: 'CAD' } })).textContent).toContain('Free');
  });

  it('shows a night that has happened as proof, not an offer', () => {
    const card = draw(summary({ availability: { state: 'sold_out', left: null }, is_sold_out: true }), { past: true });

    expect(card.textContent).toContain('Past event');
    expect(card.querySelector('[data-tone]')).toBeNull();
    expect(card.textContent).not.toContain('Get tickets');
  });

  it('carries the badge on the wide listing row as well', () => {
    const row = draw(summary({ availability: { state: 'almost_sold_out', left: null } }), { wide: true });

    expect(row.querySelector('[data-tone="scarce"]')?.textContent?.trim()).toBe('Almost sold out');
  });

  it('draws its own picture, with no image request, when there is no poster', () => {
    const card = draw(summary({ poster_url: null }));

    expect(card.querySelector('img')).toBeNull();
    expect(card.querySelector('app-poster-art')).not.toBeNull();
  });
});

describe('AvailabilityBadge', () => {
  function draw(value: unknown, exact = false): HTMLElement {
    const fixture = TestBed.createComponent(AvailabilityBadge);
    fixture.componentRef.setInput('value', value);
    fixture.componentRef.setInput('exact', exact);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('names the count only where asked, and only when the API gave one', () => {
    expect(draw({ state: 'almost_sold_out', left: 4 }, true).textContent?.trim()).toBe('Only 4 left');
    expect(draw({ state: 'almost_sold_out', left: null }, true).textContent?.trim()).toBe('Almost sold out');
    expect(draw({ state: 'almost_sold_out', left: 4 }).textContent?.trim()).toBe('Almost sold out');
  });

  it('says a closed tier or night is closed, in the sold tone', () => {
    const badge = draw({ state: 'closed', left: null }, true);

    expect(badge.textContent?.trim()).toBe('Sales closed');
    expect(badge.querySelector('[data-tone="sold"]')).not.toBeNull();
  });

  it('draws nothing when there is nothing to say', () => {
    expect(draw({ state: 'available', left: null }).textContent?.trim()).toBe('');
    expect(draw({ state: 'unlimited', left: null }).textContent?.trim()).toBe('');
    expect(draw(undefined).textContent?.trim()).toBe('');
  });
});
