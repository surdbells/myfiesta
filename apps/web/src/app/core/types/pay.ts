/** Paying later with Klarna or Affirm through Stripe. */

/** A lender Stripe's page can offer, in Stripe's word for it. */
export type PayLaterProvider = 'klarna' | 'affirm';

/**
 * Who a buyer can pay a night later with, on its event page (`pay_later`),
 * null wherever they cannot. Never an instalment amount: the lender quotes
 * those on its own page.
 */
export interface PayLater {
  providers: PayLaterProvider[];
}

/**
 * Whether a basket can be paid for later, and with whom, on a quote
 * (`pay_later`), null wherever the night does not offer it. Not eligible,
 * naming nobody, when no lender takes an order that size.
 */
export interface PayLaterQuote {
  eligible: boolean;
  providers: PayLaterProvider[];
}
