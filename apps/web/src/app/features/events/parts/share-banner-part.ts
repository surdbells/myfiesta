import { isPlatformBrowser } from '@angular/common';
import { Component, OnInit, PLATFORM_ID, computed, effect, inject, input, signal } from '@angular/core';
import { EventDetail } from '../../../core/api.types';
import { CheckoutStore } from '../../../core/checkout-store';
import { ShareApi } from '../../share/share-api';
import { isFriendLink, percentOf } from '../../share/share';

/**
 * The banner for somebody who arrived through a friend's link: what the
 * friend discount saves them.
 *
 * The friend-discount feature's own file. The event page places it once, at
 * the head of the words about the night, and never edits it again. Which
 * link they came by is the `ref` the page keeps for checkout
 * (CheckoutStore); this reads it from there rather than being handed it, so
 * a friend who wanders off and comes back without the ?ref= is still greeted.
 *
 * Only once the server has said the link takes money off. The ref's shape
 * cannot say so on its own: a promoter can pick a slug that looks like a
 * friend's link, and a link stops working once its holder is refunded. A
 * greeting promising a discount checkout then does not give is worse than
 * none. In the browser only: the page is rendered on the server for
 * everybody alike.
 *
 * Names nobody: the page is the same for everybody, and who sent the link is
 * the sender's business.
 *
 * `contents`, so the part adds no box of its own to the column's grid:
 * empty, it takes no row and no gap.
 */
@Component({
  selector: 'app-share-banner-part',
  host: { class: 'contents' },
  template: `
    @if (saving(); as percent) {
      <p
        class="friend-banner m-0 rounded-(--radius-card) border border-[color-mix(in_srgb,var(--success)_30%,transparent)] bg-[color-mix(in_srgb,var(--success)_10%,transparent)] px-5 py-4 text-sm text-text"
        role="status"
      >
        <strong class="block text-base text-success">A friend sent you this, so you save {{ percent }}</strong>
        It comes off your tickets at checkout. Once you have paid, your friend gets the same off their next tickets.
      </p>
    }
  `,
})
export class ShareBannerPart implements OnInit {
  private readonly store = inject(CheckoutStore);
  private readonly share = inject(ShareApi);
  private readonly browser = isPlatformBrowser(inject(PLATFORM_ID));

  readonly event = input.required<EventDetail>();

  /** What the server says this visitor's link takes off, in basis points; null until it has said so. */
  private readonly confirmed = signal<number | null>(null);

  /** "15%" when this visitor came by a working friend's link; null otherwise. */
  readonly saving = computed(() => {
    const bps = this.confirmed();

    return bps === null ? null : percentOf(bps);
  });

  constructor() {
    effect((onCleanup) => {
      const event = this.event();
      const ref = this.store.slug() === event.slug ? this.store.ref() : null;

      this.confirmed.set(null);

      // A night with no offer, or a ref that cannot be a friend's link, is
      // not worth asking about.
      if (!this.browser || !event.share_offer || !ref || !isFriendLink(ref)) return;

      const asking = this.share.friendDiscount(event.slug, ref.trim()).subscribe({
        next: (offer) => this.confirmed.set(offer.discount_bps),
        // Not a working friend's link, or no answer: no greeting either way.
        error: () => this.confirmed.set(null),
      });

      onCleanup(() => asking.unsubscribe());
    });
  }

  ngOnInit(): void {
    // This night's basket, where a friend's link from an earlier visit is
    // kept: the ticket page loads it the same way.
    this.store.loadFor(this.event().slug);
  }
}
