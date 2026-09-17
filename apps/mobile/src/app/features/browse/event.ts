import { Component, computed, inject, input, signal } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { Router } from '@angular/router';
import { Browser } from '@capacitor/browser';
import { Share } from '@capacitor/share';
import { Capacitor } from '@capacitor/core';
import { Discover, EventPage, TicketTypeCard } from '../../core/discovery';
import { longEventTime } from '../../core/event-time';
import { formatMoney } from '../../core/money';
import {
  MfBadge,
  MfButton,
  MfCard,
  MfCarousel,
  MfEmpty,
  MfPoster,
  MfScreen,
  MfSheet,
  MfSkeleton,
  ToastStore,
} from '../../ui';

/**
 * One event: the poster, the night, what it costs, and the way in.
 *
 * Buying hands off to the web checkout in a system browser rather than
 * rebuilding a payment flow in here. Tickets are physical goods, so store
 * in-app purchase rules do not apply, and the checkout that already exists is
 * the one that handles both gateways, the holds, the codes and the receipts —
 * a second implementation would be a second set of money bugs.
 *
 * The description is sanitized markup from the API. It is rendered through
 * Angular's sanitizer rather than trusted: the server cleans it on the way in,
 * and this is the second lock on the same door.
 */
@Component({
  selector: 'mf-event',
  imports: [MfScreen, MfCard, MfBadge, MfButton, MfPoster, MfCarousel, MfEmpty, MfSkeleton, MfSheet],
  template: `
    <mf-screen [title]="event()?.title ?? 'Event'" back flush (backed)="leave()">
      @if (canShare) {
        <button mfButton variant="ghost" size="sm" screenActions (click)="share()">Share</button>
      }

      @if (loading()) {
        <div class="pad">
          <mf-skeleton height="12rem" />
          <mf-skeleton class="mt" height="2rem" width="70%" />
          <mf-skeleton class="mt" height="6rem" />
        </div>
      } @else if (failed(); as message) {
        <mf-empty title="Could not load this event" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (event(); as night) {
        <mf-poster class="banner" [url]="night.poster_url" [title]="night.title" shape="wide" />

        <div class="pad">
          <p class="when">{{ when(night) }}</p>
          <h1>{{ night.title }}</h1>

          <p class="where">
            {{ night.venue?.name ?? night.city }}
            @if (night.venue?.address) {
              <span class="subtle">· {{ night.venue?.address }}</span>
            }
          </p>

          <div class="tags">
            @if (night.category) {
              <mf-badge>{{ night.category }}</mf-badge>
            }
            @if (night.min_age) {
              <mf-badge tone="warning">{{ night.min_age }}+</mf-badge>
            }
            @if (night.id_required) {
              <mf-badge tone="warning">Photo ID</mf-badge>
            }
            @if (night.dress_code) {
              <mf-badge>{{ night.dress_code }}</mf-badge>
            }
          </div>

          <div class="calendar">
            <a class="link" [href]="night.calendar.google_url" target="_blank" rel="noopener">Add to Google Calendar</a>
            <span class="subtle" aria-hidden="true">·</span>
            <a class="link" [href]="night.calendar.ics_url">Apple or Outlook</a>
          </div>

          @if (past()) {
            <mf-card class="block" quiet>
              <h3>This one has happened</h3>
              <p class="subtle">
                {{ night.gallery.length > 0 ? 'Pictures from the night are below.' : 'Follow the organizer to hear about the next one.' }}
              </p>
            </mf-card>
          } @else {
            <section class="tickets">
              <h2 class="section">Tickets</h2>

              @for (tier of night.ticket_types; track tier.id) {
                <mf-card class="tier" quiet>
                  <div class="tier__row">
                    <div class="tier__text">
                      <h3>{{ tier.name }}</h3>
                      @if (tier.description) {
                        <p class="subtle">{{ tier.description }}</p>
                      }
                      @if (tier.waiting && tier.opens_after) {
                        <p class="subtle">Opens when {{ tier.opens_after.name }} sells out</p>
                      } @else if (tier.remaining !== null && tier.remaining > 0 && tier.remaining <= 10) {
                        <p class="few">Only {{ tier.remaining }} left</p>
                      }
                    </div>

                    <div class="tier__price">
                      <span class="amount">{{ tier.price.amount === 0 ? 'Free' : money(tier.price) }}</span>
                      @if (tier.sold_out) {
                        <mf-badge>Sold out</mf-badge>
                      } @else if (tier.waiting) {
                        <mf-badge tone="warning">Not yet</mf-badge>
                      }
                    </div>
                  </div>
                </mf-card>
              } @empty {
                <mf-card quiet>
                  <p class="subtle">Nothing on sale right now.</p>
                </mf-card>
              }
            </section>
          }

          @if (night.description) {
            <section>
              <h2 class="section">About</h2>
              <div class="prose" [innerHTML]="description()"></div>
            </section>
          }

          @if (night.gallery.length > 0) {
            <section>
              <h2 class="section">Pictures</h2>
              <mf-carousel ariaLabel="Pictures from this event" [count]="night.gallery.length">
                @for (picture of night.gallery; track picture.url) {
                  <figure class="shot">
                    <img [src]="picture.thumb_url" [alt]="picture.caption ?? ''" loading="lazy" />
                    @if (picture.caption) {
                      <figcaption class="subtle">{{ picture.caption }}</figcaption>
                    }
                  </figure>
                }
              </mf-carousel>
            </section>
          }

          <section>
            <h2 class="section">Organizer</h2>
            <mf-card quiet>
              <p class="who">
                {{ night.organizer.name }}
                @if (night.organizer.is_verified) {
                  <mf-badge tone="success">Verified</mf-badge>
                }
              </p>
              @if (night.organizer.description) {
                <p class="subtle">{{ night.organizer.description }}</p>
              }
            </mf-card>
          </section>
        </div>

        @if (!past()) {
          <!-- The way in, always reachable: a buy button that scrolls off the
               screen is a buy button nobody presses. -->
          <div class="buy">
            @if (!anyTickets()) {
              <div class="buy__text">
                <span class="amount">Not on sale yet</span>
                <span class="subtle">The organizer has not opened tickets for this one</span>
              </div>
            } @else if (soldOut()) {
              <div class="buy__text">
                <span class="amount">Sold out</span>
                <span class="subtle">Join the waitlist and we will tell you if more open</span>
              </div>
              <button mfButton size="lg" variant="secondary" (click)="waitlist.set(true)">Waitlist</button>
            } @else {
              <div class="buy__text">
                <span class="amount">{{ from(night) }}</span>
                <span class="subtle">Checkout opens in your browser</span>
              </div>
              <button mfButton size="lg" label="Opening…" [loading]="opening()" (click)="buy(night)">Get tickets</button>
            }
          </div>
        }
      }
    </mf-screen>

    <mf-sheet
      [open]="waitlist()"
      heading="Join the waitlist"
      subheading="If tickets open up, the people waiting are told first."
      (closed)="waitlist.set(false)"
    >
      <p class="subtle">
        The waitlist is on the website, so you can enter your details once and keep the email that
        comes with it.
      </p>
      <button mfButton class="mt" block (click)="openWaitlist()">Open the waitlist</button>
    </mf-sheet>
  `,
  styles: `
    .pad {
      padding: var(--space-5);
    }

    .banner {
      border-radius: 0;
    }

    .when {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      color: var(--primary-text);
    }

    h1 {
      margin-top: var(--space-1);
      font-size: var(--font-size-2xl);
    }

    .where {
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .tags {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-top: var(--space-3);
    }

    .calendar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2);
      margin-top: var(--space-4);
      font-size: var(--font-size-sm);
    }

    .link {
      color: var(--primary-text);
      font-weight: var(--font-weight-medium);
      text-decoration: none;
    }

    .section {
      margin: var(--space-6) 0 var(--space-3);
      font-size: var(--font-size-xs);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-subtle);
    }

    .block {
      display: block;
      margin-top: var(--space-5);
    }

    .tickets {
      display: grid;
      gap: var(--space-2);
    }

    .tier {
      display: block;
    }

    .tier__row {
      display: flex;
      align-items: flex-start;
      gap: var(--space-4);
    }

    .tier__text {
      flex: 1;
      min-width: 0;
      display: grid;
      gap: 2px;
    }

    .tier__price {
      display: grid;
      justify-items: end;
      gap: var(--space-2);
      text-align: right;
    }

    .amount {
      font-weight: var(--font-weight-semibold);
      white-space: nowrap;
    }

    .few {
      font-size: var(--font-size-sm);
      color: var(--warning);
    }

    .subtle {
      font-size: var(--font-size-sm);
    }

    .prose {
      line-height: var(--font-leading-normal);
    }

    .prose ::ng-deep p {
      margin: 0 0 var(--space-3);
    }

    .prose ::ng-deep ul,
    .prose ::ng-deep ol {
      margin: 0 0 var(--space-3);
      padding-left: var(--space-5);
    }

    .prose ::ng-deep h3 {
      margin: var(--space-4) 0 var(--space-2);
    }

    .shot {
      margin: 0;
      display: grid;
      gap: var(--space-2);
    }

    .shot img {
      display: block;
      width: 100%;
      border-radius: var(--radius-lg);
    }

    .who {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-weight: var(--font-weight-medium);
    }

    .buy {
      position: sticky;
      bottom: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-4);
      padding: var(--space-3) var(--space-5) calc(var(--mf-safe-bottom) + var(--space-3));
      background: var(--surface);
      border-top: 1px solid var(--border-subtle);
    }

    .buy__text {
      display: grid;
      min-width: 0;
    }

    .mt {
      margin-top: var(--space-4);
    }
  `,
})
export class Event {
  private readonly discover = inject(Discover);
  private readonly router = inject(Router);
  private readonly sanitizer = inject(DomSanitizer);
  private readonly toasts = inject(ToastStore);

  /** From the route. */
  readonly slug = input.required<string>();

  readonly event = signal<EventPage | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);
  readonly opening = signal(false);
  readonly waitlist = signal(false);

  readonly canShare = Capacitor.isNativePlatform() || typeof navigator !== 'undefined';

  readonly past = computed(() => {
    const night = this.event();

    return !!night && new Date(night.ends_at ?? night.starts_at).getTime() < Date.now();
  });

  /** Something a person could buy right now. */
  readonly onSale = computed(() =>
    (this.event()?.ticket_types ?? []).filter((tier) => !tier.sold_out && !tier.waiting),
  );

  /** Anything at all on the event, sold out or not: no tiers is not a sell-out. */
  readonly anyTickets = computed(() => (this.event()?.ticket_types ?? []).length > 0);

  readonly soldOut = computed(() => this.anyTickets() && this.onSale().length === 0);

  readonly description = computed<SafeHtml>(() =>
    this.sanitizer.bypassSecurityTrustHtml(this.event()?.description ?? ''),
  );

  constructor() {
    queueMicrotask(() => void this.load());
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      this.event.set(await this.discover.event(this.slug()));
    } catch (error) {
      this.failed.set(error instanceof Error ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  when(night: EventPage): string {
    return longEventTime(night.starts_at, night.timezone);
  }

  money = formatMoney;

  from(night: EventPage): string {
    const cheapest = this.onSale().reduce<TicketTypeCard | null>(
      (low, tier) => (low === null || tier.price.amount < low.price.amount ? tier : low),
      null,
    );

    if (!cheapest) return 'Tickets';

    return cheapest.price.amount === 0 ? 'Free' : `From ${formatMoney(cheapest.price)}`;
  }

  /**
   * The checkout, in the system browser.
   *
   * Not an in-app WebView pretending to be the app: a payment page needs its
   * own address bar and the phone's saved cards, and putting it inside the app
   * is how a buyer cannot tell whether the page asking for a card is the real
   * one.
   */
  async buy(night: EventPage): Promise<void> {
    this.opening.set(true);

    try {
      await Browser.open({ url: this.siteUrl(`/${night.slug}/tickets`), presentationStyle: 'popover' });
    } catch {
      this.toasts.show('Could not open checkout. Try the website.', 'danger');
    } finally {
      this.opening.set(false);
    }
  }

  async openWaitlist(): Promise<void> {
    const night = this.event();
    if (!night) return;

    this.waitlist.set(false);
    await Browser.open({ url: this.siteUrl(`/${night.slug}`) });
  }

  async share(): Promise<void> {
    const night = this.event();
    if (!night) return;

    const url = this.siteUrl(`/${night.slug}`);

    try {
      await Share.share({ title: night.title, text: `${night.title} — ${this.when(night)}`, url });
    } catch {
      // Dismissed, or no share sheet on this platform. The link is on screen.
    }
  }

  private siteUrl(path: string): string {
    // The public site sits beside the API: api.myfiesta.ca -> myfiesta.ca in
    // production, and the dev ports in development.
    const base = this.discover.siteBase();

    return base + path;
  }

  leave(): void {
    void this.router.navigate(['/']);
  }
}
