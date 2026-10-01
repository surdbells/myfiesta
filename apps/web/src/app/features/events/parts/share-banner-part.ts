import { Component, input } from '@angular/core';
import { EventDetail } from '../../../core/api.types';

/**
 * The banner for somebody who arrived through a friend's link: what the
 * friend discount saves them (`share_offer`, null for a night with none).
 *
 * The friend-discount feature's own file. The event page places it once, at
 * the head of the words about the night, and never edits it again. Which
 * link they came by is the `ref` the page keeps for checkout
 * (CheckoutStore); this reads it from there rather than being handed it.
 * Until the feature fills it, it draws nothing.
 *
 * `contents`, so the part adds no box of its own to the column's grid:
 * empty, it takes no row and no gap.
 */
@Component({
  selector: 'app-share-banner-part',
  host: { class: 'contents' },
  template: ``,
})
export class ShareBannerPart {
  readonly event = input.required<EventDetail>();
}
