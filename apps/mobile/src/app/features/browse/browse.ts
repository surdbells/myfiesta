import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Discover, EventCard } from '../../core/discovery';
import { shortEventTime } from '../../core/event-time';
import { formatMoney } from '../../core/money';
import {
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfPoster,
  MfScreen,
  MfSelect,
  MfSkeleton,
  type MfOption,
} from '../../ui';

/**
 * Searching for something to go to.
 *
 * The search box asks the server: full-text over titles, descriptions and
 * cities lives in Postgres, and a phone holding a page of results cannot
 * search the ones it has not fetched.
 *
 * Filters are deliberately three — where, what kind, and free — because on a
 * phone every additional filter is a row of screen that pushes the results
 * further down, and these are the three people actually use.
 */
@Component({
  selector: 'mf-browse',
  imports: [FormsModule, MfScreen, MfCard, MfField, MfSelect, MfPoster, MfEmpty, MfSkeleton, MfButton],
  template: `
    <mf-screen title="Find something on" back (backed)="leave()">
      <div class="filters">
        <mf-field label="Search">
          <input
            #control
            name="q"
            type="search"
            inputmode="search"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            enterkeyhint="search"
            placeholder="Afrobeats, brunch, a venue…"
            [ngModel]="query()"
            (ngModelChange)="search($event)"
          />
        </mf-field>

        <div class="pair">
          <mf-select
            heading="Where"
            placeholder="Everywhere"
            ariaLabel="City"
            [options]="cityOptions()"
            [value]="city()"
            (valueChange)="setCity($event)"
          />

          <mf-select
            heading="What kind"
            placeholder="Anything"
            ariaLabel="Category"
            [options]="categoryOptions()"
            [value]="category()"
            (valueChange)="setCategory($event)"
          />
        </div>

        <label class="free">
          <input type="checkbox" [checked]="free()" (change)="setFree($any($event.target).checked)" />
          <span>Free entry only</span>
        </label>
      </div>

      @if (loading()) {
        <div class="stack">
          @for (n of [0, 1, 2, 3]; track n) {
            <mf-card quiet><mf-skeleton height="3.5rem" /></mf-card>
          }
        </div>
      } @else if (failed(); as message) {
        <mf-empty title="Could not search" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (results().length === 0) {
        <mf-empty
          title="Nothing matches"
          hint="Try fewer words, another city, or clear the filters."
        >
          @if (filtered()) {
            <button mfButton variant="secondary" (click)="clear()">Clear filters</button>
          }
        </mf-empty>
      } @else {
        <ul class="stack">
          @for (event of results(); track event.slug) {
            <li>
              <mf-card quiet tappable (click)="open(event)">
                <div class="row">
                  <mf-poster class="thumb" [url]="event.poster_url" [title]="event.title" shape="square" />
                  <div class="lines">
                    <p class="when">{{ when(event) }}</p>
                    <h3>{{ event.title }}</h3>
                    <p class="subtle">{{ event.city }}</p>
                  </div>
                  <span class="price">{{ price(event) }}</span>
                </div>
              </mf-card>
            </li>
          }
        </ul>

        @if (hasMore()) {
          <button mfButton class="more" variant="secondary" block label="Loading…" [loading]="loadingMore()" (click)="more()">
            Show more
          </button>
        }
      }
    </mf-screen>
  `,
  styles: `
    .filters {
      display: grid;
      gap: var(--space-3);
      margin-bottom: var(--space-5);
    }

    .pair {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-3);
    }

    .free {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      min-height: var(--mf-tap);
      font-size: var(--font-size-sm);
    }

    .free input {
      width: 1.25rem;
      height: 1.25rem;
      accent-color: var(--primary);
    }

    .stack {
      display: grid;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
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
    .lines .subtle {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
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

    .subtle {
      font-size: var(--font-size-sm);
    }

    .price {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      white-space: nowrap;
    }

    .more {
      margin-top: var(--space-4);
    }
  `,
})
export class Browse {
  private readonly discover = inject(Discover);
  private readonly router = inject(Router);

  readonly results = signal<EventCard[]>([]);
  readonly loading = signal(true);
  readonly loadingMore = signal(false);
  readonly failed = signal<string | null>(null);
  readonly hasMore = signal(false);

  readonly query = signal('');
  readonly city = signal<string | null>(null);
  readonly category = signal<string | null>(null);
  readonly free = signal(false);

  readonly cities = signal<{ city: string; country: string; events: number }[]>([]);
  readonly categories = signal<{ value: string; label: string }[]>([]);

  readonly filtered = computed(
    () => this.query().trim() !== '' || !!this.city() || !!this.category() || this.free(),
  );

  readonly cityOptions = computed<MfOption[]>(() => [
    { value: '', label: 'Everywhere' },
    ...this.cities().map((row) => ({ value: row.city, label: row.city, hint: `${row.events} on` })),
  ]);

  readonly categoryOptions = computed<MfOption[]>(() => [
    { value: '', label: 'Anything' },
    ...this.categories().map((row) => ({ value: row.value, label: row.label })),
  ]);

  private page = 1;
  private typing: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    void this.load();

    // The filter lists come from the same place the home screen gets them.
    void this.discover
      .home()
      .then((home) => {
        this.cities.set(home.cities);
        this.categories.set(home.categories);
      })
      .catch(() => undefined);
  }

  async load(): Promise<void> {
    this.page = 1;
    this.loading.set(true);
    this.failed.set(null);

    try {
      const { data, hasMore } = await this.discover.search(this.filters());

      this.results.set(data);
      this.hasMore.set(hasMore);
    } catch (error) {
      this.failed.set(error instanceof Error ? error.message : 'Something went wrong.');
    } finally {
      this.loading.set(false);
    }
  }

  async more(): Promise<void> {
    if (this.loadingMore()) return;

    this.loadingMore.set(true);
    this.page += 1;

    try {
      const { data, hasMore } = await this.discover.search({ ...this.filters(), page: this.page });

      this.results.update((all) => [...all, ...data]);
      this.hasMore.set(hasMore);
    } catch {
      this.page -= 1;
    } finally {
      this.loadingMore.set(false);
    }
  }

  private filters() {
    return {
      q: this.query(),
      city: this.city(),
      category: this.category(),
      free: this.free(),
    };
  }

  /** One request per pause, not per letter. */
  search(value: string): void {
    this.query.set(value);

    if (this.typing) clearTimeout(this.typing);

    this.typing = setTimeout(() => void this.load(), 300);
  }

  setCity(city: string | null): void {
    this.city.set(city || null);
    void this.load();
  }

  setCategory(category: string | null): void {
    this.category.set(category || null);
    void this.load();
  }

  setFree(free: boolean): void {
    this.free.set(free);
    void this.load();
  }

  clear(): void {
    this.query.set('');
    this.city.set(null);
    this.category.set(null);
    this.free.set(false);
    void this.load();
  }

  when(event: EventCard): string {
    return shortEventTime(event.starts_at, event.timezone);
  }

  price(event: EventCard): string {
    if (!event.from_price) return '';

    return event.from_price.amount === 0 ? 'Free' : formatMoney(event.from_price);
  }

  open(event: EventCard): void {
    void this.router.navigate(['/e', event.slug]);
  }

  leave(): void {
    void this.router.navigate(['/']);
  }
}
