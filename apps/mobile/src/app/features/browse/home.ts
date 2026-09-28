import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Search } from 'lucide-angular';
import { Discover, EventCard } from '../../core/discovery';
import { SessionStore } from '../../core/session';
import { shortEventTime } from '../../core/event-time';
import { formatMoney } from '../../core/money';
import {
  MfScreen,
  MfIconButton,
  MfButton,
  MfCard,
  MfCarousel,
  MfEmpty,
  MfPoster,
  MfSelect,
  MfSkeleton,
  type MfOption,
} from '../../ui';
import { MfAvailability, offSale } from './availability';

/**
 * What is on.
 *
 * The first screen for anybody who is not working tonight, and it needs no
 * account: browsing and buying are open on this platform, and an app that
 * opens on a password box reads as one you cannot use without signing up.
 *
 * Three shelves, in the order somebody actually asks for them. What is coming
 * up that is worth planning around (the carousel), everything else that is
 * coming up (the list), and what happened recently — which is there because
 * last month's pictures are what sell next month's tickets to whoever missed
 * it.
 */
@Component({
  selector: 'mf-home',
  imports: [MfScreen, MfIconButton, MfCarousel, MfPoster, MfCard, MfButton, MfEmpty, MfSkeleton, MfSelect, MfAvailability],
  template: `
    <mf-screen title="What’s on" [subtitle]="greeting()" large>
      <button mfIconButton screenActions tone="tonal" [icon]="searchIcon" label="Search events" (click)="go('/browse')"></button>

      <div class="content">
        @if (cityOptions().length > 2) {
          <mf-select
            class="city"
            heading="Where"
            placeholder="Everywhere"
            ariaLabel="City"
            [options]="cityOptions()"
            [value]="city()"
            (valueChange)="setCity($event)"
          />
        }

        @if (loading()) {
          <mf-skeleton height="13rem" />
          <div class="stack">
            @for (n of [0, 1, 2]; track n) {
              <mf-card quiet><mf-skeleton height="4rem" /></mf-card>
            }
          </div>
        } @else if (failed(); as message) {
          <mf-empty title="Could not load what’s on" [hint]="message">
            <button mfButton variant="secondary" (click)="load()">Try again</button>
          </mf-empty>
        } @else {
          @if (featured().length > 0) {
            <mf-carousel ariaLabel="Featured events" [count]="featured().length">
              @for (event of featured(); track event.slug) {
                <article class="hero" (click)="open(event)">
                  <mf-poster [url]="event.poster_url" [title]="event.title" shape="wide" />
                  <div class="hero__text">
                    <p class="when">{{ when(event) }}</p>
                    <h2>{{ event.title }}</h2>
                    <p class="where subtle">{{ event.city }}</p>
                    <p class="deal">
                      @if (!offSale(event.availability)) {
                        <span class="price figure">{{ price(event) }}</span>
                      }
                      <mf-availability [value]="event.availability" />
                    </p>
                  </div>
                </article>
              }
            </mf-carousel>
          }

          @if (saved().length > 0) {
            <section>
              <header class="shelf">
                <h2>Saved</h2>
                <button class="link" type="button" (click)="go('/saved')">See all</button>
              </header>

              <mf-carousel ariaLabel="Saved events" [count]="saved().length">
                @for (event of saved(); track event.slug) {
                  <article class="recent" (click)="open(event)">
                    <mf-poster [url]="event.poster_url" [title]="event.title" shape="wide" />
                    <p class="when subtle">{{ when(event) }}</p>
                    <h3>{{ event.title }}</h3>
                  </article>
                }
              </mf-carousel>
            </section>
          }

          @if (upcoming().length > 0) {
            <section>
              <header class="shelf">
                <h2>Coming up</h2>
                <button class="link" type="button" (click)="go('/browse')">See all</button>
              </header>

              <ul class="stack">
                @for (event of upcoming(); track event.slug) {
                  <li>
                    <mf-card quiet tappable (click)="open(event)">
                      <div class="row">
                        <mf-poster class="thumb" [url]="event.poster_url" [title]="event.title" shape="square" />
                        <div class="lines">
                          <p class="when">{{ when(event) }}</p>
                          <h3>{{ event.title }}</h3>
                          <p class="where subtle">{{ event.city }}</p>
                        </div>
                        <!-- The price, and a badge once it is going or gone:
                             "Sold out" or "Sales closed" stands in for a price
                             nobody can pay. -->
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
            </section>
          } @else {
            <mf-empty
              title="Nothing coming up here yet"
              [hint]="city() ? 'Try another city, or clear the filter.' : 'Check back soon — organizers publish all week.'"
            />
          }

          @if (past().length > 0) {
            <section>
              <header class="shelf">
                <h2>Recently</h2>
                <p class="subtle">It happened; the pictures are up</p>
              </header>

              <mf-carousel ariaLabel="Recent events" [count]="past().length">
                @for (event of past(); track event.slug) {
                  <article class="recent" (click)="open(event)">
                    <mf-poster [url]="event.poster_url" [title]="event.title" shape="wide" />
                    <p class="when subtle">{{ when(event) }}</p>
                    <h3>{{ event.title }}</h3>
                  </article>
                }
              </mf-carousel>
            </section>
          }

          @if (!session.signedIn()) {
            <mf-card class="prompt">
              <h3>Already have tickets?</h3>
              <p class="subtle">Sign in and they are on your phone, ready to scan.</p>
              <button mfButton class="mt" variant="secondary" block (click)="go('/sign-in')">Sign in</button>
            </mf-card>
          }
        }
      </div>
    </mf-screen>
  `,
  styles: `
    .content {
      display: grid;
      align-content: start;
      gap: var(--space-6);
    }

    /* A carousel's cards are sized as a share of its own width, so its
       smallest width is the whole row of them; without this the column grew
       to fit it and every shelf ran off the right of the screen. */
    .content > * {
      min-width: 0;
    }

    .city {
      margin-bottom: calc(var(--space-6) * -1 + var(--space-1));
    }

    .hero {
      display: grid;
      gap: var(--space-3);
      cursor: pointer;
    }

    .hero__text {
      display: grid;
      gap: 2px;
    }

    .when {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      color: var(--primary-text);
      /* A wrapped date squeezes the title into an ellipsis beside it. */
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .where {
      font-size: var(--font-size-sm);
    }

    .price {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      white-space: nowrap;
    }

    /* The hero's price and badge on one line; the badge wraps under a long
       price rather than squeezing it. */
    .deal {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2);
    }

    .end {
      flex: none;
      display: grid;
      justify-items: end;
      gap: var(--space-1);
    }

    .shelf {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: var(--space-3);
      margin-bottom: var(--space-3);
    }

    .shelf .subtle {
      font-size: var(--font-size-sm);
    }

    .link {
      border: 0;
      background: transparent;
      color: var(--primary-text);
      font-family: inherit;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      cursor: pointer;
    }

    .stack {
      display: grid;
      gap: var(--space-2);
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
      gap: var(--space-3);
    }

    .thumb {
      width: 3.5rem;
      flex: none;
    }

    .lines {
      flex: 1;
      min-width: 0;
      display: grid;
      gap: 1px;
    }

    .lines h3,
    .lines .where {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .recent {
      display: grid;
      gap: var(--space-2);
      cursor: pointer;
    }

    .recent h3 {
      font-size: var(--font-size-base);
    }

    .prompt {
      display: block;
    }

    .mt {
      margin-top: var(--space-3);
    }
  `,
})
export class Home {
  protected readonly searchIcon = Search;
  protected readonly offSale = offSale;

  private readonly discover = inject(Discover);
  private readonly router = inject(Router);
  readonly session = inject(SessionStore);

  readonly featured = signal<EventCard[]>([]);
  readonly upcoming = signal<EventCard[]>([]);
  readonly past = signal<EventCard[]>([]);

  /**
   * What this phone's account kept for later. Empty for a guest, and the shelf
   * disappears with it rather than inviting somebody to sign in twice — the
   * prompt at the bottom of the screen already does that once.
   */
  readonly saved = signal<EventCard[]>([]);
  readonly cities = signal<{ city: string; country: string; events: number }[]>([]);

  readonly city = signal<string | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly cityOptions = computed<MfOption[]>(() => [
    { value: '', label: 'Everywhere' },
    ...this.cities().map((row) => ({
      value: row.city,
      label: row.city,
      hint: `${row.events} ${row.events === 1 ? 'event' : 'events'}`,
    })),
  ]);

  readonly greeting = computed(() => {
    const name = this.session.session()?.name?.split(' ')[0];
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Morning' : hour < 17 ? 'Afternoon' : 'Evening';

    return name ? `${part}, ${name}` : part;
  });

  constructor() {
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.failed.set(null);

    try {
      const [home, past, saved] = await Promise.all([
        this.discover.home(this.city()),
        // A shelf that fails should not take the screen with it.
        this.discover.past(this.city()).catch(() => []),
        this.session.signedIn() && !this.session.locked()
          ? this.discover.saved().catch(() => [])
          : Promise.resolve([]),
      ]);

      this.featured.set(home.featured);
      // The carousel already has the featured ones; repeating them directly
      // underneath makes the list look like a bug.
      const shown = new Set(home.featured.map((event) => event.slug));
      this.upcoming.set(home.upcoming.filter((event) => !shown.has(event.slug)));
      this.cities.set(home.cities);
      this.past.set(past);
      this.saved.set(saved);
    } catch (error) {
      this.failed.set(error instanceof Error ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  setCity(city: string | null): void {
    this.city.set(city || null);
    void this.load();
  }

  when(event: EventCard): string {
    return shortEventTime(event.starts_at, event.timezone);
  }

  price(event: EventCard): string {
    if (!event.from_price) return '';

    return event.from_price.amount === 0 ? 'Free' : `From ${formatMoney(event.from_price)}`;
  }

  open(event: EventCard): void {
    void this.router.navigate(['/e', event.slug]);
  }

  go(path: string): void {
    void this.router.navigate([path]);
  }
}
