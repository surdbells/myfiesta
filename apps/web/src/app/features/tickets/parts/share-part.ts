import { Component, Injector, afterNextRender, computed, inject, input, signal } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { TicketAccess } from '../../../core/api.types';
import { percentOf } from '../../share/share';

/**
 * The buyer's own friend-discount link for this night, to pass on: a friend
 * who buys through it saves, and so does the buyer, once the friend has paid
 * (each ticket's `share_link`, null where the night has no offer).
 *
 * The friend-discount feature's own file. The tickets page places it once,
 * under the tickets, and never edits it again. It is handed the whole order,
 * since the link is the buyer's for the night rather than any one ticket's.
 *
 * Sharing goes through the device's share sheet, which is itself the moment
 * of choosing who gets it; where there is none, the link is copied, and
 * where the clipboard is refused it is shown, selected, to copy by hand —
 * as the event page's own share button does. The link is not the tickets
 * link, and the block says so, because this page's own address must never
 * be the one passed on.
 *
 * `contents`, so the part adds no box of its own to the page: empty, it
 * takes no space.
 */
@Component({
  selector: 'app-share-part',
  host: { class: 'contents' },
  template: `
    @if (link(); as mine) {
      <section class="share-link mt-8 rounded-xl border border-border bg-surface-raised p-5" aria-labelledby="share-link-heading">
        <h2 id="share-link-heading" class="m-0 text-base font-semibold">Bring a friend, and you both save</h2>
        <p class="mt-2 mb-0 text-sm text-text-muted">
          Send a friend your link. They get {{ percent() }} off their tickets, and once they have paid you get
          {{ percent() }} off your next tickets{{ mine.organizer ? ' from ' + mine.organizer : '' }}, by email.
        </p>
        @if (mine.rewards_earned > 0 || mine.rewards_left === 0) {
          <p class="rewards mt-2 mb-0 text-sm">
            @if (mine.rewards_left === 0) {
              Your link has earned all {{ mine.rewards_earned }} of its codes. Friends still save with it.
            } @else {
              Earned so far: {{ mine.rewards_earned }} {{ mine.rewards_earned === 1 ? 'code' : 'codes' }}.
              {{ mine.rewards_left }} more {{ mine.rewards_left === 1 ? 'friend' : 'friends' }} can earn you one.
            }
          </p>
        }

        <div class="mt-4 flex flex-wrap items-center gap-3">
          <button
            class="h-11 cursor-pointer rounded-md border-0 bg-primary px-5 text-sm font-semibold text-on-primary [font-family:inherit]"
            type="button"
            (click)="share(mine.url!)"
          >
            Share your link
          </button>
          @if (copied()) {
            <span class="text-sm text-success" role="status">Link copied</span>
          }
        </div>

        @if (showLink()) {
          <label class="mt-3 block text-xs text-text-muted" for="friend-link">Copy your link</label>
          <input id="friend-link" class="mt-1 w-full" type="text" readonly [value]="mine.url" />
        }

        <p class="mt-3 mb-0 text-xs text-text-subtle">This is not the link to your tickets, so it is safe to pass on.</p>
      </section>
    }
  `,
})
export class SharePart {
  private readonly document = inject(DOCUMENT);
  private readonly injector = inject(Injector);

  readonly order = input.required<TicketAccess>();

  /** The buyer's link, from whichever ticket carries it; every one on the order carries the same. */
  readonly link = computed(() => this.order().tickets.map((ticket) => ticket.share_link).find((link) => !!link?.url) ?? null);

  readonly percent = computed(() => percentOf(this.link()?.discount_bps ?? 0));

  readonly copied = signal(false);
  /** Shown to copy by hand, where neither a share sheet nor the clipboard would take it. */
  readonly showLink = signal(false);

  async share(url: string): Promise<void> {
    const navigator = this.document.defaultView?.navigator;

    if (navigator?.share) {
      try {
        await navigator.share({ title: 'Tickets for less', text: `Use my link and save ${this.percent()} on tickets.`, url });

        return;
      } catch (error) {
        // Dismissed the sheet: nothing to do. Refused by the browser is
        // another matter, and the clipboard is tried next.
        if (error instanceof DOMException && error.name === 'AbortError') return;
      }
    }

    try {
      await navigator!.clipboard.writeText(url);
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    } catch {
      this.showLink.set(true);
      afterNextRender(() => this.document.querySelector<HTMLInputElement>('#friend-link')?.select(), { injector: this.injector });
    }
  }
}
