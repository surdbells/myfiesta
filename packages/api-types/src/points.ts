/** Fiesta Points, and the perks an organizer offers for them. */

/**
 * Something Fiesta Points buy at a night, on its event page (`perks`, empty
 * when it offers nothing). Its fields arrive with the feature; until then
 * the list is always empty.
 */
export interface Perk {}

/**
 * A perk a ticket's holder claimed for the night, on the ticket (`perks`,
 * empty when none). Its fields arrive with the feature; until then the list
 * is always empty.
 */
export interface ClaimedPerk {}
