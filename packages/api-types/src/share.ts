/** Friend discounts: the offer an organizer runs, and the links and rewards it makes. */

/**
 * The friend discount a night offers, on its event page (`share_offer`),
 * null where it offers none. Its fields arrive with the feature; until then
 * the API always sends null.
 */
export interface ShareOffer {}

/**
 * A holder's own friend-discount link for a night, on their ticket
 * (`share_link`), null where the night has no offer. Its fields arrive with
 * the feature; until then the API always sends null.
 */
export interface ShareLink {}

/**
 * A night's friend discount as its organizer set it, in the console
 * (`share_offer` on OrganizerEventDetail), null where there is none. Its
 * fields arrive with the feature; until then the API always sends null.
 */
export interface OrganizerShareOffer {}

/*
 * Fields this feature adds to a shape of index.ts, declared here rather than
 * there so no two features edit that file (TypeScript merges the two).
 */
declare module './index' {
  interface OrganizerEventDetail {
    /** Optional for a copy the console kept from before the API said. */
    share_offer?: OrganizerShareOffer | null;
  }
}
