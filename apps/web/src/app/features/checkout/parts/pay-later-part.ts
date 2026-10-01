import { Component, input } from '@angular/core';
import { EventDetail, Quote } from '../../../core/api.types';

/**
 * "Pay over time with Klarna or Affirm — choose it on the payment page",
 * where a night can be paid for later (`pay_later`). Never an instalment
 * amount: those are the processor's to quote, on its own page.
 *
 * The pay-later feature's own file. Checkout places it once, under what it
 * says about the payment page, and never edits it again. It is handed the
 * quote as well as the event, since whether this basket can be paid later is
 * the quote's to say (`quote.pay_later`). Until the feature fills it, it
 * draws nothing.
 *
 * `contents`, so the part adds no box of its own to the form: empty, it
 * takes no space.
 */
@Component({
  selector: 'app-pay-later-part',
  host: { class: 'contents' },
  template: ``,
})
export class PayLaterPart {
  readonly event = input.required<EventDetail>();
  readonly quote = input.required<Quote>();
}
