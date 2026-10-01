import { Component, computed, inject, input, signal } from '@angular/core';
import { Share } from '@capacitor/share';
import type { ShareLink } from '@myfiesta/api-types';
import { ApiError, type Ticket } from '../../core/api';
import { MfButton, MfCard, ToastStore } from '../../ui';
import { ShareApi } from './share-api';

/**
 * The holder's friend-discount link for this night, from the ticket's
 * `share_link`: send it, and a friend who buys with it saves, and so do they.
 *
 * Null where the night has no offer, and then this takes no room: a night
 * with no offer should not carry an empty block about one. A buyer's link is
 * on the ticket already; somebody a ticket was passed on to has none until
 * they ask ("Get my link"), which makes theirs.
 *
 * Sharing goes through the phone's own share sheet, which is itself the
 * moment of choosing who gets it. The link is the event page, not this
 * ticket, and the block says so: the code above must never be what is
 * passed on.
 */
@Component({
  selector: 'mf-ticket-share',
  imports: [MfButton, MfCard],
  template: `
    @if (link(); as share) {
      <mf-card class="share">
        <h2>Bring a friend, and you both save</h2>
        <p class="subtle">
          A friend who buys with your link gets {{ percent() }} off their tickets. Once they have paid, you get
          {{ percent() }} off your next tickets{{ share.organizer ? ' from ' + share.organizer : '' }}, by email.
        </p>

        @if (share.rewards_left === 0) {
          <p class="earned">Your link has earned all {{ share.rewards_earned }} of its codes. Friends still save with it.</p>
        } @else if (share.rewards_earned > 0) {
          <p class="earned">
            Earned so far: {{ share.rewards_earned }} {{ share.rewards_earned === 1 ? 'code' : 'codes' }}.
            {{ share.rewards_left }} more {{ share.rewards_left === 1 ? 'friend' : 'friends' }} can earn you one.
          </p>
        }

        @if (share.url; as url) {
          <button mfButton block (click)="send(url)">Share your link</button>
          <p class="url subtle tabular">{{ url }}</p>
        } @else {
          <button mfButton block label="Getting your link…" [loading]="asking()" [disabled]="asking()" (click)="ask()">
            Get my link
          </button>
        }

        <p class="note subtle">This is not your ticket, so it is safe to pass on.</p>
      </mf-card>
    }
  `,
  styles: `
    .share {
      display: grid;
      gap: var(--space-3);
      margin-top: var(--space-4);
    }

    h2 {
      margin: 0;
      font-size: var(--font-size-base);
    }

    p {
      margin: 0;
      font-size: var(--font-size-sm);
    }

    .url {
      overflow-wrap: anywhere;
      user-select: all;
    }

    .note {
      font-size: var(--font-size-xs);
    }
  `,
})
export class MfTicketShare {
  private readonly api = inject(ShareApi);
  private readonly toast = inject(ToastStore);

  /** The ticket on screen. */
  readonly ticket = input.required<Ticket>();

  /** The link this screen asked for, once it has: the ticket's own is from before. */
  private readonly asked = signal<ShareLink | null>(null);
  readonly asking = signal(false);

  /** Only for a ticket that still gets somebody in; one given back has nothing to share. */
  readonly link = computed<ShareLink | null>(() => {
    const ticket = this.ticket();

    if (!['valid', 'checked_in'].includes(ticket.status)) return null;

    return this.asked() ?? ticket.share_link ?? null;
  });

  readonly percent = computed(() => `${Number(((this.link()?.discount_bps ?? 0) / 100).toFixed(2))}%`);

  async send(url: string): Promise<void> {
    try {
      await Share.share({
        title: 'Tickets for less',
        text: `Use my link and save ${this.percent()} on tickets for ${this.ticket().event.title}.`,
        url,
      });
    } catch {
      // Dismissed, or no share sheet on this platform. The link is on screen.
    }
  }

  async ask(): Promise<void> {
    this.asking.set(true);

    try {
      this.asked.set(await this.api.link(this.ticket().event.slug));
    } catch (error) {
      this.toast.show(error instanceof ApiError ? error.message : 'Your link could not be made. Try again.', 'danger');
    } finally {
      this.asking.set(false);
    }
  }
}
