import { Component, input, model } from '@angular/core';
import { AboutYou, EventDetail } from '../../../core/api.types';

/**
 * "About you (optional)": an age range, a gender, the first three characters
 * of a postal code, and an unticked box agreeing to share them as anonymous
 * totals.
 *
 * The audience feature's own file. Checkout places it once, after the
 * organizer's questions and before the payment step, and never edits it
 * again. What the buyer says goes out through `answers`, which checkout sends
 * with the order as it stands, so the feature fills in both the form and
 * what it sends without touching checkout. Until then it draws nothing and
 * leaves `answers` null, and an order is sent exactly as before.
 *
 * `contents`, so the part adds no box of its own to the form: empty, it
 * takes no space.
 */
@Component({
  selector: 'app-about-you-part',
  host: { class: 'contents' },
  template: ``,
})
export class AboutYouPart {
  readonly event = input.required<EventDetail>();
  /** What to send with the order; null for nothing. */
  readonly answers = model<AboutYou | null>(null);
}
