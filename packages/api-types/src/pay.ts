/** Paying later with Klarna or Affirm through Stripe. */

/**
 * How a night can be paid for later, on its event page (`pay_later`), which
 * is null wherever it cannot be. Its fields arrive with the feature; until
 * then the API always sends null.
 */
export interface PayLater {}

/*
 * Fields this feature adds to a shape of index.ts, declared here rather than
 * there so no two features edit that file (TypeScript merges the two).
 */
declare module './index' {
  interface OrganizerEventDetail {
    /**
     * Whether the organizer has let buyers pay for this night later. Optional
     * for a copy the console kept from before the API said.
     */
    pay_later_enabled?: boolean;
  }
}
