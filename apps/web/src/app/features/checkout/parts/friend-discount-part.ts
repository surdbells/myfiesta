import { Component, computed, input } from '@angular/core';
import { EventDetail, Quote } from '../../../core/api.types';
import { formatMoney } from '../../../core/money';
import { percentOf } from '../../share/share';

/**
 * "Friend's discount": what a friend's link takes off this order, said in the
 * summary under the code field, where a discount is looked for.
 *
 * The friend-discount feature's own file. Checkout places it once and never
 * edits it again. The figures are the quote's, as every figure on the page
 * is (`quote.friend_discount`; the quote never names its hidden code as
 * `code_applied`, so the page shows no code to remove). Typing a code
 * replaces it — one code to an order — which is said here, so a buyer who
 * types one is not surprised to lose this.
 *
 * `contents`, so the part adds no box of its own to the summary: empty, it
 * takes no space.
 */
@Component({
  selector: 'app-friend-discount-part',
  host: { class: 'contents' },
  template: `
    @if (quote().friend_discount; as friend) {
      <p
        class="friend-discount mt-3 mb-0 flex items-center justify-between gap-3 rounded-md bg-[color-mix(in_srgb,var(--success)_10%,transparent)] px-3 py-2 text-sm text-success"
        role="status"
      >
        <span>
          <strong>Friend’s discount</strong>, {{ percent() }} off
          <span class="block text-xs">From the link a friend sent you. A code you type instead replaces it.</span>
        </span>
        <span class="shrink-0 font-medium tabular-nums">−{{ amount() }}</span>
      </p>
    }
  `,
})
export class FriendDiscountPart {
  readonly event = input.required<EventDetail>();
  readonly quote = input.required<Quote>();

  readonly percent = computed(() => percentOf(this.quote().friend_discount?.discount_bps ?? 0));
  readonly amount = computed(() => formatMoney(this.quote().friend_discount?.amount ?? null));
}
