import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
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
  MfField,
  MfOption,
  MfPoster,
  MfScreen,
  MfSelect,
  MfSheet,
  MfSkeleton,
  ToastStore,
} from '../../ui';
import { SessionStore } from '../../core/session';

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
  imports: [
    FormsModule,
    MfScreen,
    MfCard,
    MfBadge,
    MfButton,
    MfPoster,
    MfCarousel,
    MfEmpty,
    MfSkeleton,
    MfSheet,
    MfField,
    MfSelect,
  ],
  template: `
    <mf-screen [title]="event()?.title ?? 'Event'" back flush backTo="/">
      <span class="actions" screenActions>
        @if (!past()) {
          <button
            mfButton
            variant="ghost"
            size="sm"
            [attr.aria-pressed]="saved()"
            [attr.aria-label]="saved() ? 'Saved' : 'Save for later'"
            (click)="toggleSave()"
          >
            {{ saved() ? 'Saved' : 'Save' }}
          </button>
        }

        @if (canShare) {
          <button mfButton variant="ghost" size="sm" (click)="share()">Share</button>
        }
      </span>

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
                      <span class="amount figure">{{ tier.price.amount === 0 ? 'Free' : money(tier.price) }}</span>
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
            <!-- Tapping the card opens their page — everything they have on,
                 and everything they have run. The follow button inside it is
                 the one thing that is not a way through. -->
            <mf-card quiet [tappable]="!!night.organizer.slug" (click)="openOrganizer()">
              <div class="host">
                @if (night.organizer.logo_url) {
                  <img class="mark" [src]="night.organizer.logo_url" alt="" width="44" height="44" />
                } @else {
                  <span class="mark mark--letter" aria-hidden="true">{{ organizerInitial(night) }}</span>
                }

                <p class="who">
                  {{ night.organizer.name }}
                  @if (night.organizer.is_verified) {
                    <mf-badge tone="success">Verified</mf-badge>
                  }
                </p>
              </div>
              @if (night.organizer.description) {
                <p class="subtle">{{ night.organizer.description }}</p>
              }

              @if (night.organizer.slug) {
                <button
                  mfButton
                  class="mt"
                  size="sm"
                  [variant]="following() ? 'secondary' : 'primary'"
                  [loading]="followBusy()"
                  [attr.aria-pressed]="following()"
                  (click)="$event.stopPropagation(); toggleFollow()"
                >
                  {{ following() ? 'Following' : 'Follow' }}
                </button>
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
                <span class="amount figure">{{ from(night) }}</span>
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
      @if (joined()) {
        <p class="joined">{{ joined() }}</p>
        <button mfButton class="mt" block variant="secondary" (click)="waitlist.set(false)">Done</button>
      } @else {
        <form class="form" (ngSubmit)="join()">
          <mf-field label="Name" optional>
            <input
              name="waitlist-name"
              type="text"
              autocomplete="name"
              enterkeyhint="next"
              [ngModel]="name()"
              (ngModelChange)="name.set($event)"
            />
          </mf-field>

          <mf-field label="Email" [error]="wrong()">
            <input
              name="waitlist-email"
              type="email"
              inputmode="email"
              autocomplete="email"
              autocapitalize="off"
              autocorrect="off"
              enterkeyhint="go"
              required
              [ngModel]="email()"
              (ngModelChange)="email.set($event)"
            />
          </mf-field>

          <div class="how-many">
            <span class="label">How many</span>
            <mf-select
              heading="How many tickets"
              ariaLabel="How many"
              [options]="quantities"
              [value]="quantity()"
              (valueChange)="quantity.set($event ?? '1')"
            />
          </div>

          <button mfButton type="submit" size="lg" block label="Adding you…" [loading]="joining()">
            Join the waitlist
          </button>
        </form>
      }
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

    /* The button keeps its words on one line and the sentence beside it
       gives way — a two-line button reads as a broken one. */
    .buy [mfButton] {
      flex: none;
      white-space: nowrap;
    }

    .buy__text {
      display: grid;
      min-width: 0;
    }

    .host {
      display: flex;
      align-items: center;
      gap: var(--space-4);
    }

    .mark {
      flex: none;
      width: 2.75rem;
      height: 2.75rem;
      border-radius: var(--radius-full);
      object-fit: cover;
      background: var(--surface-inset);
    }

    .mark--letter {
      display: grid;
      place-items: center;
      background: var(--primary-soft);
      color: var(--primary-soft-text);
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-bold);
    }

    .actions {
      display: flex;
      align-items: center;
      gap: var(--space-1);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .how-many {
      display: grid;
      gap: var(--space-2);
    }

    .how-many .label {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      color: var(--text);
    }

    .joined {
      margin: 0;
      color: var(--text);
      font-size: var(--font-size-base);
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
  private readonly session = inject(SessionStore);

  /** From the route. */
  readonly slug = input.required<string>();

  readonly event = signal<EventPage | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);
  readonly opening = signal(false);
  readonly waitlist = signal(false);
  readonly name = signal('');
  readonly email = signal('');
  readonly quantity = signal('1');
  readonly joining = signal(false);
  readonly wrong = signal<string | null>(null);
  readonly joined = signal<string | null>(null);

  /**
   * Saving and following, answered on the phone first.
   *
   * A tap that waits for a round trip before it looks like anything reads as a
   * broken button, so the star fills immediately and puts itself back if the
   * server disagrees. Both are also hidden from a guest — there is nowhere to
   * keep the list — and the sign-in ask comes when they tap, not before.
   */
  readonly saved = signal(false);
  readonly following = signal(false);
  readonly followBusy = signal(false);

  /** One to ten, the range the server accepts. */
  readonly quantities: MfOption[] = Array.from({ length: 10 }, (_, i) => ({
    value: String(i + 1),
    label: i === 0 ? '1 ticket' : `${i + 1} tickets`,
  }));

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

    // Somebody signed in should not retype what the app already knows, and a
    // sheet reopened after a mistake should not still be showing the error.
    effect(() => {
      if (!this.waitlist()) return;

      const who = this.session.session();

      if (who && !this.email()) {
        this.name.set(who.name ?? '');
        this.email.set(who.email ?? '');
      }

      this.wrong.set(null);
    });
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      const night = await this.discover.event(this.slug());

      this.event.set(night);
      this.saved.set(night.saved === true);
      this.following.set(night.organizer.following === true);
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

  /** Stands in for a mark an organizer has not uploaded. */
  organizerInitial(night: EventPage): string {
    return (night.organizer.name?.trim().charAt(0) ?? '?').toUpperCase();
  }

  from(night: EventPage): string {
    const cheapest = this.onSale().reduce<TicketTypeCard | null>(
      (low, tier) => (low === null || tier.price.amount < low.price.amount ? tier : low),
      null,
    );

    if (!cheapest) return 'Tickets';

    return cheapest.price.amount === 0 ? 'Free' : `From ${formatMoney(cheapest.price)}`;
  }

  async toggleSave(): Promise<void> {
    const night = this.event();
    if (!night) return;

    if (!this.session.signedIn()) {
      this.toasts.show('Sign in to keep this for later.');
      await this.router.navigate(['/sign-in'], { queryParams: { next: `/e/${night.slug}` } });

      return;
    }

    const next = !this.saved();
    this.saved.set(next);

    try {
      await this.discover.save(night.slug, next);
      this.toasts.show(next ? 'Saved for later.' : 'Taken off your list.');
    } catch {
      this.saved.set(!next);
      this.toasts.show('Could not save that. Try again.', 'danger');
    }
  }

  /** Their page: what else that name is putting on. */
  openOrganizer(): void {
    const slug = this.event()?.organizer.slug;

    if (slug) void this.router.navigate(['/o', slug]);
  }

  async toggleFollow(): Promise<void> {
    const slug = this.event()?.organizer.slug;
    if (!slug || this.followBusy()) return;

    if (!this.session.signedIn()) {
      this.toasts.show('Sign in to follow this organizer.');
      await this.router.navigate(['/sign-in'], { queryParams: { next: `/e/${this.slug()}` } });

      return;
    }

    const next = !this.following();
    this.following.set(next);
    this.followBusy.set(true);

    try {
      await this.discover.follow(slug, next);
    } catch {
      this.following.set(!next);
      this.toasts.show('Could not do that. Try again.', 'danger');
    } finally {
      this.followBusy.set(false);
    }
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

  /**
   * Joining, in the app.
   *
   * The server answers the same whether the address was new or already on the
   * list, so nobody can use this to find out who is waiting for what. The app
   * repeats that answer rather than inventing a friendlier one.
   */
  async join(): Promise<void> {
    const night = this.event();
    if (!night || this.joining()) return;

    const email = this.email().trim();

    if (!email.includes('@')) {
      this.wrong.set('We need an email address to tell you on.');

      return;
    }

    this.joining.set(true);
    this.wrong.set(null);

    try {
      const { message } = await this.discover.waitlist(night.slug, {
        name: this.name().trim(),
        email,
        quantity: Number(this.quantity()),
      });

      this.joined.set(message);
    } catch (error) {
      this.wrong.set(error instanceof Error ? error.message : 'Could not add you. Try again.');
    } finally {
      this.joining.set(false);
    }
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
}
