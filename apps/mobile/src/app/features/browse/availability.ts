import { Component, computed, input } from '@angular/core';
import { type Availability, availabilityBadge } from '@myfiesta/shared/availability';

export type { Availability };

/** Sold out or sales closed: where the badge stands in for a price nobody can pay. */
export { offSale } from '@myfiesta/shared/availability';

/**
 * "Almost sold out", "Only 4 left", "Sold out" — or nothing.
 *
 * The same words the site uses, from the same rule
 * (packages/shared/availability), so a night that reads "Almost sold out" on
 * a laptop does not read "Few left" on a phone. The state is the API's,
 * counted the way checkout counts, holds and all; this only draws it.
 *
 * A solid fill with its own label colour — the scarce and sold tokens, whose
 * pairs the contrast check holds in both themes — because the same badge sits
 * on a card, beside a ticket and over a poster. Nothing at all when there is
 * nothing to say: "Available" on every row says nothing on any of them.
 */
@Component({
  selector: 'mf-availability',
  template: `
    @if (badge(); as shown) {
      <span class="badge" [class.sold]="shown.tone === 'sold'" [attr.data-tone]="shown.tone">{{ shown.label }}</span>
    }
  `,
  styles: `
    :host {
      display: contents;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      padding: 3px var(--space-2);
      border-radius: var(--radius-full);
      background: var(--scarce);
      color: var(--on-scarce);
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      line-height: 1.2;
      letter-spacing: 0.01em;
      white-space: nowrap;
    }

    .badge.sold {
      background: var(--sold);
      color: var(--on-sold);
    }
  `,
})
export class MfAvailability {
  readonly value = input<Availability | null | undefined>(null);

  /**
   * Name the count where the API gave one: beside a ticket, where the number
   * is the reason somebody decides now. A card in a list says "Almost sold
   * out" instead — a column of rows each with a different count reads as a
   * market stall.
   */
  readonly exact = input(false);

  readonly badge = computed(() => availabilityBadge(this.value(), { exact: this.exact() }));
}
