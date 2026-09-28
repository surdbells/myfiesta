import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { UiIcon, UiSelect, type SelectOption } from '@myfiesta/ui';
import { MapPin, Search, SlidersHorizontal, X } from 'lucide-angular';
import { Observable, Subscription } from 'rxjs';
import { Api, EventQuery, When } from '../../core/api';
import { CategoryPlace, CityPlace, EventSummary } from '../../core/api.types';
import { Seo } from '../../core/seo';
import { EventCard } from '../../shared/event-card';
import { OrganizerPitch } from '../../shared/organizer-pitch';
import { PosterArt } from '../../shared/poster-art';

type WhenChoice = 'any' | Exclude<When, 'upcoming'> | 'dates';
type PriceChoice = 'all' | 'free' | 'paid';
type SortChoice = 'soon' | 'price-asc' | 'price-desc';
type AvailabilityChoice = 'any' | 'on_sale' | 'almost_sold_out' | 'sold_out';

/** Which page this is: the whole listing, or one category's or one city's. */
type Collection = { kind: 'category'; place: CategoryPlace } | { kind: 'city'; place: CityPlace } | null;

const WHEN_WORDS: Record<Exclude<WhenChoice, 'any' | 'dates'>, string> = {
  today: 'Happening today',
  weekend: 'This weekend',
  month: 'This month',
  past: 'Past events',
};

/**
 * Browse and search: a filter rail beside the results, in the shape every
 * large ticketing catalogue settles on — filters are the product on this
 * screen, not an afterthought above it.
 *
 * The server narrows everything it can: text, city, category, when (today,
 * this weekend, this month, past, or a range of days — each in the event's own
 * zone), availability and free. Only the paid-only case and the price sort are
 * applied here, over the pages loaded. Pagination is a cursor, so "load more"
 * rather than numbered pages a cursor cannot jump to.
 *
 * The same screen is a category's page (/events/category/comedy) and a
 * city's (/events/city/lagos): the one filter fixed, and a title, a picture
 * and a description of their own — the page a search for "comedy in Lagos"
 * should land on. A slug the API does not know answers 404.
 */
@Component({
  selector: 'mf-event-list',
  standalone: true,
  imports: [FormsModule, RouterLink, UiIcon, UiSelect, EventCard, OrganizerPitch, PosterArt],
  templateUrl: './event-list.html',
})
export class EventList {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly searchIcon = Search;
  protected readonly whereIcon = MapPin;
  protected readonly filtersIcon = SlidersHorizontal;
  protected readonly clearIcon = X;

  readonly events = signal<EventSummary[]>([]);
  readonly loading = signal(true);
  readonly failed = signal(false);

  /** Which page this is, once the API has said the category or city exists. */
  readonly collection = signal<Collection>(null);
  readonly notFound = signal(false);
  readonly unavailable = signal(false);

  /**
   * Whether the filters are open, on a phone.
   *
   * Open, they filled the whole first screen before a single event — somebody
   * who came to browse had to scroll past a form to see anything. Closed by
   * default below 860px; always open beside the results on a wide screen,
   * where the CSS ignores this.
   */
  readonly filtersOpen = signal(false);
  readonly loadingMore = signal(false);
  readonly nextCursor = signal<string | null>(null);

  readonly query = signal('');
  readonly city = signal('');
  readonly category = signal('');
  readonly when = signal<WhenChoice>('any');
  readonly dateFrom = signal('');
  readonly dateTo = signal('');
  readonly availability = signal<AvailabilityChoice>('any');
  readonly price = signal<PriceChoice>('all');
  readonly sort = signal<SortChoice>('soon');

  readonly sortOptions: SelectOption[] = [
    { value: 'soon', label: 'Soonest' },
    { value: 'price-asc', label: 'Price: low to high' },
    { value: 'price-desc', label: 'Price: high to low' },
  ];

  readonly whenChoices: { value: WhenChoice; label: string }[] = [
    { value: 'any', label: 'Any upcoming date' },
    { value: 'today', label: 'Today' },
    { value: 'weekend', label: 'This weekend' },
    { value: 'month', label: 'This month' },
    { value: 'dates', label: 'Pick dates…' },
    { value: 'past', label: 'Past events' },
  ];

  readonly availabilityChoices: { value: AvailabilityChoice; label: string }[] = [
    { value: 'any', label: 'Any' },
    { value: 'on_sale', label: 'Tickets available' },
    { value: 'almost_sold_out', label: 'Almost sold out' },
    { value: 'sold_out', label: 'Sold out' },
  ];

  /** Only categories and cities with something on — a filter leading nowhere is worse than none. */
  readonly categories = signal<CategoryPlace[]>([]);
  readonly cities = signal<CityPlace[]>([]);

  readonly cityOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Any city' },
    ...[...new Set(this.cities().map((c) => c.city))].map((name) => ({ value: name, label: name })),
  ]);

  readonly anyFilter = computed(
    () =>
      this.query() !== '' ||
      (this.city() !== '' && this.collection()?.kind !== 'city') ||
      (this.category() !== '' && this.collection()?.kind !== 'category') ||
      this.when() !== this.openingWhen() ||
      this.availability() !== 'any' ||
      this.price() !== 'all',
  );

  /** The paid-only case and the price sort, applied over what has loaded. */
  readonly visible = computed(() => {
    let list = this.events();

    if (this.price() === 'paid') {
      list = list.filter((e) => e.from_price !== null && e.from_price.amount > 0);
    }

    const sort = this.sort();
    if (sort !== 'soon') {
      const amount = (e: EventSummary) => e.from_price?.amount ?? 0;
      list = [...list].sort((a, b) => (sort === 'price-asc' ? amount(a) - amount(b) : amount(b) - amount(a)));
    }

    return list;
  });

  /** The page's own title: the place it is about, and the window it is showing. */
  readonly heading = computed(() => {
    const collection = this.collection();
    const window = this.when();
    const words = window !== 'any' && window !== 'dates' ? WHEN_WORDS[window] : null;

    if (collection?.kind === 'city') return words ? `${words} in ${collection.place.city}` : `Events in ${collection.place.city}`;
    if (collection?.kind === 'category') return words ? `${collection.place.category}: ${words.toLowerCase()}` : collection.place.category;

    return words ?? 'What’s on';
  });

  readonly subheading = computed(() => {
    const collection = this.collection();

    if (collection?.kind === 'city') {
      const count = collection.place.events;

      if (count > 0) {
        return `${count} ${count === 1 ? 'event' : 'events'} coming up in ${collection.place.city} — concerts, club nights, comedy and more.`;
      }

      return this.when() === 'past'
        ? `Nothing coming up in ${collection.place.city} right now. Past nights are below, and new ones are added all the time.`
        : `Nothing coming up in ${collection.place.city} right now — new nights are added all the time.`;
    }

    if (collection?.kind === 'category') {
      const count = collection.place.events;

      return count > 0
        ? `${count} ${count === 1 ? 'night' : 'nights'} of ${collection.place.category.toLowerCase()} coming up, wherever you are.`
        : `Nothing in ${collection.place.category.toLowerCase()} on sale right now — new nights are added all the time.`;
    }

    return 'Concerts, club nights and everything in between — near you, on sale now.';
  });

  private loads?: Subscription;

  /**
   * A city page with nothing coming up opens on its past nights.
   *
   * The API keeps a city's page for a while after its last night, so a link
   * somebody shared still lands somewhere. Opened on "any upcoming date", that
   * somewhere was an empty list under a line promising the past nights. They
   * are what there is to show, so they are what it shows, until the buyer
   * picks another window.
   */
  private readonly pastFirst = computed(() => {
    const collection = this.collection();

    return collection?.kind === 'city' && collection.place.events === 0;
  });

  /** The window a page opens on, and what clearing the filters goes back to. */
  private readonly openingWhen = computed<WhenChoice>(() => (this.pastFirst() ? 'past' : 'any'));

  constructor() {
    this.api.facets().subscribe({
      next: (facets) => {
        this.categories.set(facets.categories);
        this.cities.set(facets.cities);
      },
      error: () => undefined,
    });

    const kind = this.route.snapshot.data['collection'] as 'category' | 'city' | undefined;

    if (kind) {
      this.resolve(kind, this.route.snapshot.paramMap.get(kind) ?? '');
    } else {
      this.watchFilters();
    }
  }

  /** A category's or a city's page: find out it exists, then list what is on in it. */
  private resolve(kind: 'category' | 'city', slug: string): void {
    const found: Observable<{ data: CategoryPlace | CityPlace }> =
      kind === 'category' ? this.api.category(slug) : this.api.city(slug);

    found.subscribe({
      next: ({ data }) => {
        if (kind === 'category') {
          this.collection.set({ kind, place: data as CategoryPlace });
          this.category.set((data as CategoryPlace).category);
        } else {
          this.collection.set({ kind, place: data as CityPlace });
          this.city.set((data as CityPlace).city);
        }

        this.watchFilters();
      },
      // As the event page does: only the API saying there is no such place
      // makes it a 404. A timeout is a page to come back to (503).
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);

        if (error.status === 404) {
          this.notFound.set(true);
          this.seo.notFound(kind === 'category' ? 'Category not found' : 'City not found');
        } else {
          this.unavailable.set(true);
          this.seo.unavailable('Events unavailable');
        }
      },
    });
  }

  /**
   * Filters live in the URL so a filtered search can be shared and comes back
   * the same, and so the back button behaves.
   *
   * The front page's older links said `date=today` and `date=date&on=…`;
   * they still work.
   */
  private watchFilters(): void {
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => {
      const fixed = this.collection()?.kind;

      this.query.set(params.get('q') ?? '');
      if (fixed !== 'city') this.city.set(params.get('city') ?? '');
      if (fixed !== 'category') this.category.set(params.get('category') ?? '');
      this.price.set((params.get('price') as PriceChoice) ?? 'all');
      this.availability.set(this.pick(params.get('availability'), this.availabilityChoices.map((c) => c.value), 'any'));

      const legacy = params.get('date');
      const on = params.get('on') ?? '';
      const when = params.get('when') ?? (legacy === 'date' ? 'dates' : legacy);
      const from = params.get('date_from') ?? on;
      const to = params.get('date_to') ?? on;

      this.dateFrom.set(from);
      this.dateTo.set(to);
      this.when.set(
        from || to
          ? 'dates'
          : when === 'upcoming'
            ? 'any'
            : this.pick(when, this.whenChoices.map((c) => c.value), this.openingWhen()),
      );

      this.load();
    });
  }

  private pick<T extends string>(value: string | null, allowed: T[], fallback: T): T {
    return value !== null && (allowed as string[]).includes(value) ? (value as T) : fallback;
  }

  search(): void {
    const fixed = this.collection()?.kind;
    const dates = this.when() === 'dates';

    // "Any upcoming date" is the address with no `when` — except on a city
    // page that opens on its past nights, where it has to be said.
    const anyWhen = this.pastFirst() ? 'upcoming' : null;

    void this.router.navigate([], {
      queryParams: {
        q: this.query() || null,
        city: fixed === 'city' ? null : this.city() || null,
        category: fixed === 'category' ? null : this.category() || null,
        when: dates ? null : this.when() === 'any' ? anyWhen : this.when(),
        date_from: dates ? this.dateFrom() || null : null,
        date_to: dates ? this.dateTo() || null : null,
        availability: this.availability() === 'any' ? null : this.availability(),
        price: this.price() === 'all' ? null : this.price(),
        // Retired names, cleared so the address says one thing.
        date: null,
        on: null,
      },
      queryParamsHandling: 'merge',
    });
  }

  /** One at a time: the server takes a single category. The same one again clears it. */
  chooseCategory(name: string): void {
    this.category.set(this.category() === name ? '' : name);
    this.search();
  }

  chooseWhen(choice: WhenChoice): void {
    this.when.set(choice);
    if (choice !== 'dates') {
      this.dateFrom.set('');
      this.dateTo.set('');
    }
    this.search();
  }

  chooseAvailability(choice: AvailabilityChoice): void {
    this.availability.set(choice);
    this.search();
  }

  choosePrice(choice: PriceChoice): void {
    this.price.set(this.price() === choice ? 'all' : choice);
    this.search();
  }

  clearAll(): void {
    this.query.set('');
    if (this.collection()?.kind !== 'city') this.city.set('');
    if (this.collection()?.kind !== 'category') this.category.set('');
    this.when.set(this.openingWhen());
    this.dateFrom.set('');
    this.dateTo.set('');
    this.availability.set('any');
    this.price.set('all');
    this.search();
  }

  loadMore(): void {
    const cursor = this.nextCursor();
    if (!cursor || this.loadingMore()) return;

    this.loadingMore.set(true);
    this.api.events({ ...this.serverQuery(), cursor }).subscribe({
      next: (page) => {
        this.events.set([...this.events(), ...page.data]);
        this.nextCursor.set(page.meta?.next_cursor ?? null);
        this.loadingMore.set(false);
      },
      error: () => this.loadingMore.set(false),
    });
  }

  serverQuery(): EventQuery {
    const when = this.when();

    return {
      q: this.query() || undefined,
      city: this.city() || undefined,
      category: this.category() || undefined,
      when: when === 'any' || when === 'dates' ? undefined : when,
      date_from: when === 'dates' ? this.dateFrom() || undefined : undefined,
      date_to: when === 'dates' ? this.dateTo() || undefined : undefined,
      availability: this.availability() === 'any' ? undefined : this.availability(),
      free: this.price() === 'free' || undefined,
    };
  }

  private load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.loads?.unsubscribe();

    this.loads = this.api.events(this.serverQuery()).subscribe({
      next: (page) => {
        this.events.set(page.data);
        this.nextCursor.set(page.meta?.next_cursor ?? null);
        this.loading.set(false);
        this.describe(page.data);
      },
      error: () => {
        this.loading.set(false);
        this.failed.set(true);
      },
    });
  }

  /** The page's own title, words and picture, for search results and shared links. */
  private describe(events: EventSummary[]): void {
    const collection = this.collection();
    const base = 'https://myfiesta.ca';

    if (collection?.kind === 'city') {
      this.seo.forCollection(
        `Events in ${collection.place.city}`,
        `Tickets for concerts, club nights, comedy and festivals in ${collection.place.city}. ${this.subheading()}`.slice(0, 200),
        `${base}/events/city/${collection.place.slug}`,
        events,
        collection.place.cover_url,
      );
    } else if (collection?.kind === 'category') {
      this.seo.forCollection(
        `${collection.place.category} events and tickets`,
        `${this.subheading()} Get tickets on myFiesta in a couple of taps.`.slice(0, 200),
        `${base}/events/category/${collection.place.slug}`,
        events,
        collection.place.cover_url,
      );
    } else {
      this.seo.forCollection(this.heading(), 'Find events near you and get tickets in a couple of taps.', `${base}/events`, events);
    }
  }
}
