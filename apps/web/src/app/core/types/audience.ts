/** "About you (optional)" at checkout: answers an organizer only ever sees as totals. */

/**
 * What a buyer chose to say under "About you (optional)", sent with their
 * order as fields of its own (Checkout spreads it into the request), or null
 * when they said nothing. Its fields arrive with the feature; until then the
 * part never sets it, and an order carries nothing more than it did.
 */
export interface AboutYou {}
