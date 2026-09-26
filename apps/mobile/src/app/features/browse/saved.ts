import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Discover, EventCard } from '../../core/discovery';
import { shortEventTime } from '../../core/event-time';
import { formatMoney } from '../../core/money';
import { MfCard, MfEmpty, MfPoster, MfScreen, MfSkeleton } from '../../ui';

/**
 * Nights somebody meant to come back to.
 *
 * Their own list and nobody else's: there is no count on an event page and no
 * follower list anywhere, so saving stays a private note rather than a public
 * signal about how a night is selling.
 *
 * Soonest first, and a night that has been and gone drops off on its own —
 * saving is for later, and once later has happened the ticket screen is where
 * that evening lives.
 */
@Component({
  selector: 'mf-saved',
  imports: [MfScreen, MfCard, MfPoster, MfEmpty, MfSkeleton],
  template: `
    <mf-screen title="Saved" back backTo="/">
      @if (loading()) {
        <mf-skeleton height="5rem" />
        <mf-skeleton class="mt" height="5rem" />
        <mf-skeleton class="mt" height="5rem" />
      } @else if (failed(); as message) {
        <mf-empty title="Could not load your list" [hint]="message" />
      } @else if (events().length === 0) {
        <mf-empty
          title="Nothing saved yet"
          hint="Tap Save on a night you want to come back to and it waits here."
        />
      } @else {
        <ul class="stack">
          @for (event of events(); track event.slug) {
            <li>
              <mf-card quiet tappable (click)="open(event)">
                <div class="row">
                  <mf-poster class="thumb" [url]="event.poster_url" [title]="event.title" shape="square" />
                  <div class="lines">
                    <p class="when">{{ when(event) }}</p>
                    <h3>{{ event.title }}</h3>
                    <p class="where subtle">{{ event.city }}</p>
                  </div>
                  <span class="price figure">{{ price(event) }}</span>
                </div>
              </mf-card>
            </li>
          }
        </ul>
      }
    </mf-screen>
  `,
  styles: `
    .stack {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    /* A grid item will not shrink below its content unless told to, and the
       date on each row refuses to wrap — so without this the card grows past
       the screen instead of the date ellipsizing. */
    .stack li {
      min-width: 0;
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }

    .thumb {
      width: 4rem;
      flex: none;
    }

    .lines {
      flex: 1;
      min-width: 0;
    }

    .lines h3 {
      margin: 0;
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
      color: var(--text);
    }

    .when {
      margin: 0 0 var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--primary-text);
      /* One line: a wrapped date pushes the title off its own row. */
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .where {
      margin: var(--space-1) 0 0;
      font-size: var(--font-size-sm);
    }

    .subtle {
      color: var(--text-muted);
    }

    .price {
      flex: none;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      color: var(--text);
    }

    .mt {
      margin-top: var(--space-3);
    }
  `,
})
export class Saved {
  private readonly discover = inject(Discover);
  private readonly router = inject(Router);

  readonly events = signal<EventCard[]>([]);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  constructor() {
    queueMicrotask(() => void this.load());
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      this.events.set(await this.discover.saved());
    } catch (error) {
      this.failed.set(error instanceof Error ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  when(event: EventCard): string {
    return shortEventTime(event.starts_at, event.timezone);
  }

  price(event: EventCard): string {
    if (event.is_sold_out) return 'Sold out';
    if (!event.from_price) return '';

    return event.from_price.amount === 0 ? 'Free' : formatMoney(event.from_price);
  }

  open(event: EventCard): void {
    void this.router.navigate(['/e', event.slug]);
  }
}
