/** Friend discounts: the offer an organizer runs, and the links and rewards it makes. */

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
  /**
   * The link to send: the event page with `?ref=`. Null for a ticket
   * somebody passed on, until its holder asks for theirs
   * (POST /api/events/{slug}/share-link).
   */
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
 * A night's friend discount as its organizer set it, in the console
 * (`share_offer` on OrganizerEventDetail). Always there: `discount_bps` is
 * null while the night offers nothing, and the counts still show what an
 * ended offer did.
 */
export interface OrganizerShareOffer {
  /** What a friend saves, and a buyer's reward, in basis points. Null while there is no offer. */
  discount_bps: number | null;
  /** How many friends' orders one link earns its holder a reward for. */
  max_rewards: number;
  /** The most any night may offer, as myFiesta has it set. */
  max_bps: number;
  /** Links handed out for this night. */
  links: number;
  /** Paid orders that came through a friend's link. */
  friend_orders: number;
  /** Rewards standing (issued, not taken back). */
  rewards: number;
}

/** What a code is for: the organizer's own, or one a friend discount made. */
export type CodePurpose = 'promo' | 'share_friend' | 'share_reward';

/*
 * Fields this feature adds to a shape of index.ts, declared here rather than
 * there so no two features edit that file (TypeScript merges the two).
 */
declare module './index' {
  interface OrganizerEventDetail {
    /** Optional for a copy the console kept from before the API said. */
    share_offer?: OrganizerShareOffer | null;
  }

  interface PromoCode {
    /**
     * The organizer's own (promo), the hidden code behind a night's friend
     * discount, or a buyer's reward. Lists show the organizer's own unless
     * the others are asked for by kind. Optional for a list kept from before.
     */
    purpose?: CodePurpose;
  }
}
