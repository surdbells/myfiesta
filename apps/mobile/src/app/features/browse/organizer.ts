import { Component, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Discover, EventCard, OrganizerPage } from '../../core/discovery';
import { shortEventTime } from '../../core/event-time';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';
import {
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfPoster,
  MfScreen,
  MfSkeleton,
  ToastStore,
} from '../../ui';

/**
 * An organizer, and everything of theirs.
 *
 * Following somebody used to lead nowhere: the app would list who you follow
 * and there was no screen to open, so the only thing the list could do was
 * take a name off itself. This is where it goes.
 *
 * `/o/:slug`, the same address the public site uses, so a link shared out of
 * the app and a link opened in the app are the same link.
 *
 * Follow is answered on the phone first and put back if the server disagrees,
 * like every other one-tap decision in here. A guest is asked to sign in when
 * they tap, not before — following is the one thing on this screen that needs
 * an account, and everything else is readable without one.
 */
@Component({
  selector: 'mf-organizer',
  imports: [MfScreen, MfCard, MfBadge, MfButton, MfPoster, MfEmpty, MfSkeleton],
  template: `
    <mf-screen [title]="organizer()?.name ?? 'Organizer'" back (backed)="back()">
      @if (loading()) {
        <mf-skeleton height="6rem" />
        <mf-skeleton class="mt" height="5rem" />
        <mf-skeleton class="mt" height="5rem" />
      } @else if (failed(); as message) {
        <mf-empty title="Could not load this organizer" [hint]="message" />
      } @else if (organizer(); as org) {
        <mf-card quiet>
          <div class="host">
            @if (org.logo_url) {
              <img class="mark" [src]="org.logo_url" alt="" width="56" height="56" />
            } @else {
              <span class="mark mark--letter" aria-hidden="true">{{ initial(org) }}</span>
            }

            <p class="who">
              {{ org.name }}
              @if (org.is_verified) {
                <mf-badge tone="success">Verified</mf-badge>
              }
            </p>
          </div>

          @if (org.description) {
            <p class="subtle">{{ org.description }}</p>
          }

          <button
            mfButton
            class="mt"
            size="sm"
            [variant]="following() ? 'secondary' : 'primary'"
            [loading]="busy()"
            [attr.aria-pressed]="following()"
            (click)="toggleFollow(org)"
          >
            {{ following() ? 'Following' : 'Follow' }}
          </button>
        </mf-card>

        <h2 class="section">What's on</h2>

        @if (org.upcoming.length > 0) {
          <ul class="stack">
            @for (event of org.upcoming; track event.slug) {
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
        } @else {
          <mf-empty
            title="Nothing on sale right now"
            [hint]="
              following()
                ? 'You will hear from them when they announce a night.'
                : 'Follow them and you will hear when they announce a night.'
            "
          />
        }

        @if (org.past.length > 0) {
          <!-- What they have already run. For somebody deciding whether a name
               on an Instagram post is real, this is the part that answers it. -->
          <h2 class="section">Previously</h2>

          <ul class="stack">
            @for (event of org.past; track event.slug) {
              <li>
                <mf-card quiet tappable (click)="open(event)">
                  <div class="row">
                    <mf-poster class="thumb" [url]="event.poster_url" [title]="event.title" shape="square" />
                    <div class="lines">
                      <p class="when subtle">{{ wasWhen(event) }}</p>
                      <h3>{{ event.title }}</h3>
                      <p class="where subtle">{{ event.city }}</p>
                    </div>
                  </div>
                </mf-card>
              </li>
            }
          </ul>
        }
      }
    </mf-screen>
  `,
  styles: `
    .host {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }

    .mark {
      width: 3.5rem;
      height: 3.5rem;
      flex: none;
      border-radius: var(--radius-full);
      object-fit: cover;
      background: var(--surface-inset);
    }

    .mark--letter {
      display: grid;
      place-items: center;
      font-size: var(--font-size-xl);
      font-weight: var(--font-weight-bold);
      background: var(--primary-soft);
      color: var(--primary-soft-text);
    }

    .who {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2);
      margin: 0;
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
      color: var(--text);
    }

    .section {
      margin: var(--space-6) 0 var(--space-3);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-subtle);
    }

    .stack {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    /* A grid item will not shrink below its content unless told to, and the
       date on each row refuses to wrap. */
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
export class Organizer {
  readonly slug = input.required<string>();

  private readonly discover = inject(Discover);
  private readonly router = inject(Router);
  private readonly session = inject(SessionStore);
  private readonly toasts = inject(ToastStore);

  readonly organizer = signal<OrganizerPage | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly following = signal(false);
  readonly busy = signal(false);

  constructor() {
    queueMicrotask(() => void this.load());
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      const organizer = await this.discover.organizer(this.slug());

      this.organizer.set(organizer);
      this.following.set(organizer.following === true);
    } catch (error) {
      this.failed.set(error instanceof Error ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  initial(organizer: OrganizerPage): string {
    return organizer.name.trim().charAt(0).toUpperCase() || '?';
  }

  when(event: EventCard): string {
    return shortEventTime(event.starts_at, event.timezone);
  }

  /**
   * A night that has gone: its date, without the clock.
   *
   * What time somebody should have arrived is no use to anybody now, and the
   * year is what keeps last August from reading as next August.
   */
  wasWhen(event: EventCard): string {
    return new Intl.DateTimeFormat('en-CA', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  price(event: EventCard): string {
    if (event.is_sold_out) return 'Sold out';
    if (!event.from_price) return '';

    return event.from_price.amount === 0 ? 'Free' : formatMoney(event.from_price);
  }

  async toggleFollow(organizer: OrganizerPage): Promise<void> {
    if (this.busy()) return;

    if (!this.session.signedIn()) {
      this.toasts.show('Sign in to follow this organizer.');
      await this.router.navigate(['/sign-in'], { queryParams: { next: `/o/${organizer.slug}` } });

      return;
    }

    const next = !this.following();
    this.following.set(next);
    this.busy.set(true);

    try {
      await this.discover.follow(organizer.slug, next);
      this.toasts.show(next ? `Following ${organizer.name}.` : `You no longer follow ${organizer.name}.`);
    } catch {
      this.following.set(!next);
      this.toasts.show('Could not do that. Try again.', 'danger');
    } finally {
      this.busy.set(false);
    }
  }

  open(event: EventCard): void {
    void this.router.navigate(['/e', event.slug]);
  }

  back(): void {
    void this.router.navigate(['/']);
  }
}
