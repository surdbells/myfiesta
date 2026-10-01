/** Friend discounts: the offer a night makes, and a holder's own link to it. */

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
 * What a friend's link took off a basket, on a quote (`friend_discount`),
 * null where none did. Shown as its own line, never as a code the buyer
 * typed. Its fields arrive with the feature; until then the API always
 * sends null.
 */
export interface FriendDiscount {}
