import { Component, input } from '@angular/core';
import { EventDetail, Quote } from '../../../core/api.types';

/**
 * "Friend's discount": what a friend's link takes off this order, said in the
 * summary under the code field, where a discount is looked for.
 *
 * The friend-discount feature's own file. Checkout places it once and never
 * edits it again. The figures are the quote's, as every figure on the page
 * is (`quote.friend_discount`; the quote never names its hidden code as
 * `code_applied`, so the page shows no code to remove). Until the feature
 * fills it, it draws nothing.
 *
 * `contents`, so the part adds no box of its own to the summary: empty, it
 * takes no space.
 */
@Component({
  selector: 'app-friend-discount-part',
  host: { class: 'contents' },
  template: ``,
})
export class FriendDiscountPart {
  readonly event = input.required<EventDetail>();
  readonly quote = input.required<Quote>();
}
