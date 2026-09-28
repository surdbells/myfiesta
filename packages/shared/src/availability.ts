/**
 * How much is left of a ticket, or of a whole night, as a buyer is told it.
 *
 * The API decides the state, counted the way checkout counts — tickets that
 * still admit somebody, plus baskets in progress — and names an exact number
 * only once it is small. This file only decides the words, once, so the site
 * and the phone cannot say "Few left" in one place and "Almost gone" in the
 * other about the same tier.
 */

/**
 * `closed` is nothing to buy without it all having gone: the organizer closed
 * the tier, or its sales ended with places left. Said as "Sales closed",
 * never "Sold out" — a night that sold two tickets did not sell out.
 */
export type AvailabilityState = 'available' | 'almost_sold_out' | 'sold_out' | 'unlimited' | 'closed';

export interface Availability {
  state: AvailabilityState;
  /** The exact number left, only when it is at or under the admin's setting. */
  left: number | null;
}

/** Warm for "nearly gone", near-black for "gone" — the scarce and sold tokens. */
export type AvailabilityTone = 'scarce' | 'sold';

export interface AvailabilityBadge {
  label: string;
  tone: AvailabilityTone;
}

/**
 * The badge, or null when there is nothing worth saying.
 *
 * `exact` asks for "Only 4 left" where the API gave a number — right beside a
 * ticket, where the number is the reason somebody decides now. A card in a
 * list leaves it out and says "Almost sold out": a row of cards each shouting
 * a different count reads as a market stall.
 */
export function availabilityBadge(
  availability: Availability | null | undefined,
  options: { exact?: boolean } = {},
): AvailabilityBadge | null {
  if (!availability) return null;

  if (availability.state === 'sold_out') return { label: 'Sold out', tone: 'sold' };
  if (availability.state === 'closed') return { label: 'Sales closed', tone: 'sold' };

  if (options.exact && availability.left !== null && availability.left > 0) {
    return { label: `Only ${availability.left} left`, tone: 'scarce' };
  }

  if (availability.state === 'almost_sold_out') return { label: 'Almost sold out', tone: 'scarce' };

  return null;
}

/**
 * Nothing on it can be bought: sold out, or sales closed. Where this is true
 * the badge stands in for the price, which nobody can pay.
 */
export function offSale(availability: Availability | null | undefined): boolean {
  return availability?.state === 'sold_out' || availability?.state === 'closed';
}
