/** Friend discounts: the offer a night makes, and a holder's own link to it. */

import type { Money } from '../api.types';

/**
 * The friend discount a night offers, on its event page (`share_offer`),
 * null where it offers none. Says nothing about who sent a link.
 */
export interface ShareOffer {
  /** What a friend saves on each ticket, in basis points (1500 is 15%). */
  discount_bps: number;
}

/**
 * A holder's own friend-discount link for a night, on their ticket
 * (`share_link`), null where the night has no offer.
 */
export interface ShareLink {
  /** The link to send: the event page with `?ref=`. Null until its holder has one. */
  url: string | null;
  /** What the friend saves, and the holder's reward, in basis points. */
  discount_bps: number;
  /** Rewards this link has earned that still stand. */
  rewards_earned: number;
  /** How many more friends can earn the holder a reward. */
  rewards_left: number;
  /** Whose next tickets a reward is good for. */
  organizer: string | null;
}

/**
 * What a friend's link took off a basket, on a quote (`friend_discount`),
 * null where none did. Shown as its own line, never as a code the buyer
 * typed; a code the buyer types replaces it.
 */
export interface FriendDiscount {
  /** Basis points off each ticket. */
  discount_bps: number;
  /** What it came to on this basket. */
  amount: Money;
}
