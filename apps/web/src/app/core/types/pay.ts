/** Paying later with Klarna or Affirm through Stripe. */

/**
 * How a night can be paid for later, on its event page (`pay_later`), which
 * is null wherever it cannot be. Its fields arrive with the feature; until
 * then the API always sends null.
 */
export interface PayLater {}

/**
 * Whether a basket can be paid for later, and with whom, on a quote
 * (`pay_later`), null wherever it cannot be. Its fields arrive with the
 * feature; until then the API always sends null.
 */
export interface PayLaterQuote {}
