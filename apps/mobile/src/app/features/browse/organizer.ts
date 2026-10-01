import { Component, computed, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Browser } from '@capacitor/browser';
import type { SocialLink } from '@myfiesta/api-types';
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
import { MfAvailability, offSale } from './availability';
import { NETWORK_NAMES, OrganizerApi } from './organizer-api';

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
  imports: [MfScreen, MfCard, MfBadge, MfButton, MfPoster, MfEmpty, MfSkeleton, MfAvailability],
  template: `
    <mf-screen [title]="organizer()?.name ?? 'Organizer'" back backTo="/">
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

          @if (socials().length > 0) {
            <!-- Where else to find them, opened in the phone's own browser.
                 The addresses are the API's, built from the account's name. -->
            <ul class="socials" aria-label="Elsewhere">
              @for (link of socials(); track link.network) {
                <li>
                  <button type="button" class="social" (click)="openLink(link)">
                    <span class="subtle">{{ networkNames[link.network] }}</span> {{ link.label }}
                  </button>
                </li>
              }
            </ul>
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
                    <!-- "Sold out" or "Sales closed" stands in for a price nobody can pay. -->
                    <span class="end">
                      @if (!offSale(event.availability)) {
                        <span class="price figure">{{ price(event) }}</span>
                      }
                      <mf-availability [value]="event.availability" />
                    </span>
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
            @for (event of past(); track event.slug) {
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

          @if (pastHasMore() || pastFailed()) {
            @if (pastFailed()) {
              <p class="subtle more-note" role="status">Older events did not load. Try again.</p>
            }
            <button mfButton class="mt" block variant="secondary" [loading]="loadingPast()" (click)="showMorePast(org)">
              Show more past events
            </button>
          }
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

    .end {
      flex: none;
      display: grid;
      justify-items: end;
      gap: var(--space-1);
    }

    .mt {
      margin-top: var(--space-3);
    }

    .socials {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-1) var(--space-4);
      margin: var(--space-3) 0 0;
      padding: 0;
      list-style: none;
    }

    .socials li {
      min-width: 0;
    }

    /* A link's look on a button, so the tap opens the phone's own browser. */
    .social {
      min-height: 2.75rem;
      padding: 0;
      border: 0;
      background: none;
      font: inherit;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      color: var(--primary-text);
      text-align: start;
      overflow-wrap: anywhere;
      cursor: pointer;
    }

    .social .subtle {
      font-weight: var(--font-weight-regular);
    }

    .more-note {
      margin: var(--space-4) 0 0;
      font-size: var(--font-size-sm);
      text-align: center;
    }
  `,
})
export class Organizer {
  readonly slug = input.required<string>();

  protected readonly offSale = offSale;

  private readonly discover = inject(Discover);
  private readonly router = inject(Router);
  private readonly session = inject(SessionStore);
  private readonly toasts = inject(ToastStore);

  readonly organizer = signal<OrganizerPage | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly following = signal(false);
  readonly busy = signal(false);

  protected readonly networkNames = NETWORK_NAMES;
  private readonly organizers = inject(OrganizerApi);

  /** Where else to find them; none from an API that does not send them. */
  readonly socials = computed(() => this.organizer()?.socials ?? []);

  /** Older nights, asked for twelve at a time below the twelve the page came with. */
  private readonly olderPast = signal<EventCard[]>([]);
  private pastPage = 1;
  readonly pastHasMore = signal(false);
  readonly loadingPast = signal(false);
  readonly pastFailed = signal(false);

  /** Every night shown under Previously, newest first. */
  readonly past = computed(() => [...(this.organizer()?.past ?? []), ...this.olderPast()]);

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
      this.olderPast.set([]);
      this.pastPage = 1;
      this.pastHasMore.set(organizer.past_has_more === true);
      this.pastFailed.set(false);
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

  /** Their account elsewhere, in the phone's own browser, as every outside page is. */
  openLink(link: SocialLink): void {
    void Browser.open({ url: link.url }).catch(() => undefined);
  }

  /**
   * The next twelve nights under Previously.
   *
   * A night already listed here is not listed twice, should one have ended
   * and moved across since the screen loaded.
   */
  async showMorePast(organizer: OrganizerPage): Promise<void> {
    if (this.loadingPast()) return;

    this.loadingPast.set(true);
    this.pastFailed.set(false);

    try {
      const { data, meta } = await this.organizers.past(organizer.slug, this.pastPage + 1);
      const seen = new Set(this.past().map((event) => event.slug));

      this.pastPage = meta.page;
      this.olderPast.update((older) => [...older, ...data.filter((event) => !seen.has(event.slug))]);
      this.pastHasMore.set(meta.has_more);
    } catch {
      this.pastFailed.set(true);
    } finally {
      this.loadingPast.set(false);
    }
  }
}
