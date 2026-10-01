import { Component, computed, input } from '@angular/core';
import { EventDetail, Quote } from '../../../core/api.types';
import { PayLaterProvider } from '../../../core/types/pay';

/** The lenders' own names, as their buttons on Stripe's page say them. */
const NAMES: Record<PayLaterProvider, string> = {
  klarna: 'Klarna',
  affirm: 'Affirm',
};

/**
 * "Pay over time with Klarna or Affirm — choose it on the payment page",
 * where this basket can be paid for later (`quote.pay_later`). Never an
 * instalment amount: those are the lender's to quote, on its own page, and a
 * figure of ours could be wrong in a way somebody relies on.
 *
 * The pay-later feature's own file. Checkout places it once, under what it
 * says about the payment page, and never edits it again. Whether this basket
 * can be paid later is the quote's to say, not the event's: each lender takes
 * orders only within its own amounts, so a basket can outgrow one of them.
 * Says nothing when no lender takes it, since there is nothing to choose.
 *
 * `contents`, so the part adds no box of its own to the form: empty, it
 * takes no space.
 */
@Component({
  selector: 'app-pay-later-part',
  host: { class: 'contents' },
  template: `
    @if (lenders(); as names) {
      <p class="pay-later mt-3 mb-0 max-w-[65ch] rounded-lg bg-surface-sunken px-5 py-3 text-sm leading-[1.6] text-text-muted">
        <strong class="text-text">Pay over time with {{ names }}</strong> — choose it on the payment page.
      </p>
    }
  `,
})
export class PayLaterPart {
  readonly event = input.required<EventDetail>();
  readonly quote = input.required<Quote>();

  /** "Klarna or Affirm", or null when this basket cannot be paid later. */
  readonly lenders = computed(() => {
    const later = this.quote().pay_later;

    if (!later?.eligible || later.providers.length === 0) {
      return null;
    }

    return later.providers.map((provider) => NAMES[provider] ?? provider).join(' or ');
  });
}
