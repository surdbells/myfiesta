import { Component, computed, effect, inject, input, signal } from '@angular/core';
import type { EventPage } from '../../core/discovery';
import { ShareApi } from '../tickets/share-api';

/** 'f' and ten base32 characters: a friend's link on ?ref= (ShareLink::SLUG_PATTERN in the API). */
const FRIEND_LINK = /^f[a-z2-7]{10}$/i;

/**
 * "A friend sent you this, so you save 15%", on the event screen opened from
 * a friend's link.
 *
 * The friend-discount feature's own file; the event screen places it once,
 * above the tickets. The ref is the screen's own (`?ref=` on the link that
 * opened it, which it also hands on to the ticket page, where the discount is
 * taken off). Shown only once the server has said the link takes money off:
 * a promoter's slug can look like a friend's link, and a link stops working
 * once its holder is refunded, so the shape alone would promise a discount
 * checkout may not give. A night with no offer is not asked about at all.
 *
 * Names nobody. Takes no room while there is nothing to say.
 */
@Component({
  selector: 'mf-friend-discount',
  template: `
    @if (saving(); as percent) {
      <p class="friend" role="status">
        <strong>A friend sent you this, so you save {{ percent }}</strong>
        It comes off your tickets at checkout. Once you have paid, your friend gets the same off their next tickets.
      </p>
    }
  `,
  styles: `
    :host {
      display: contents;
    }

    .friend {
      margin: var(--space-4) 0 0;
      padding: var(--space-3) var(--space-4);
      border: 1px solid color-mix(in srgb, var(--success) 30%, transparent);
      border-radius: var(--radius-md);
      background: color-mix(in srgb, var(--success) 10%, transparent);
      font-size: var(--font-size-sm);
    }

    strong {
      display: block;
      color: var(--success);
      font-size: var(--font-size-base);
    }
  `,
})
export class MfFriendDiscount {
  private readonly share = inject(ShareApi);

  /** The night on screen. */
  readonly event = input.required<EventPage>();

  /** `?ref=` on the link that opened the screen, if any. */
  readonly ref = input<string | null | undefined>(null);

  /** What the server says the link takes off, in basis points; null until it has said so. */
  private readonly confirmed = signal<number | null>(null);

  /** "15%", "12.5%". */
  readonly saving = computed(() => {
    const bps = this.confirmed();

    return bps === null ? null : `${Number((bps / 100).toFixed(2))}%`;
  });

  constructor() {
    effect((onCleanup) => {
      const event = this.event();
      const ref = this.ref()?.trim() ?? '';
      let current = true;

      this.confirmed.set(null);
      onCleanup(() => (current = false));

      if (!event.share_offer || !FRIEND_LINK.test(ref)) return;

      this.share.friendDiscount(event.slug, ref).then(
        (offer) => current && this.confirmed.set(offer.discount_bps),
        // Not a working friend's link, or no answer: nothing to say either way.
        () => undefined,
      );
    });
  }
}
