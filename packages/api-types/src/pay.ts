/** Paying later with Klarna or Affirm through Stripe. */

import type { Money } from './index';

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

/** A processor's published rate: basis points of the order, plus a flat amount in minor units. */
export interface PayLaterRate {
  bps: number;
  flat: number;
}

/**
 * The organizer's opt-in for a night, with what it costs them
 * (GET and PUT /api/organizer/events/{id}/pay-later).
 */
export interface PayLaterSetting {
  /** Whether the organizer has opted the night in. */
  enabled: boolean;
  /** Whether myFiesta offers paying later on this night at all: switched on, and priced in CAD. */
  available: boolean;
  /** Whether a buyer would be offered it today, which also needs the night to be near enough. */
  offered_now: boolean;
  /** How many days before the night buyers start being offered it. */
  max_days_before_event: number;
  /** The night's currency. Paying later is only ever available in CAD. */
  currency: Money['currency'];
  /** What a card costs, and what each lender does. The organizer pays the difference. */
  fees: { card: PayLaterRate; klarna: PayLaterRate; affirm: PayLaterRate };
}

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

  interface CancellationPreview {
    /**
     * Of `orders_to_refund`, those paid with Klarna or Affirm longer ago than
     * the lender takes money back for: support returns them another way.
     * Optional for an older API that did not say.
     */
    orders_to_refund_elsewhere?: number;
  }

  interface CancellationResult {
    /**
     * Orders paid with Klarna or Affirm longer ago than the lender takes
     * money back for: not refunded, and not refundable by hand either. The
     * organizer writes to support, who returns them another way. Optional for
     * an older API that did not say.
     */
    left_for_support?: number;
  }

  interface EventSummary {
    /**
     * Signed, as the ledger has it, and part of `net`: what the organizer paid
     * for buyers paying with Klarna or Affirm, and any correction by
     * myFiesta. Optional for an older API that did not say.
     */
    adjustments?: Money;
  }
}
