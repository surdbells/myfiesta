import { CommonModule } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { UiIcon, UiSelect, type SelectOption } from '@myfiesta/ui';
import { CalendarDays, MapPin, Search } from 'lucide-angular';
import { Api } from '../../core/api';
import { EventSummary } from '../../core/api.types';
import { formatFrom } from '../../core/money';
import { Seo } from '../../core/seo';

type DateChoice = 'any' | 'today' | 'weekend' | 'date';
type PriceChoice = 'all' | 'free' | 'paid';
type SortChoice = 'soon' | 'price-asc' | 'price-desc';

/**
 * Browse and search: a filter rail beside the results, in the shape every
 * large ticketing catalogue settles on — filters are the product on this
 * screen, not an afterthought above it.
 *
 * What the server can narrow, it narrows: text, city, category, free. The
 * date window, the paid-only case and the sort are applied here, over the
 * loaded pages — the API sorts by date and paginates by cursor, and inventing
 * server parameters it does not have would only fake precision. Pagination is
 * a cursor, so "load more" rather than numbered pages a cursor cannot jump to.
 */
@Component({
  selector: 'mf-event-list',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, UiIcon, UiSelect],
  templateUrl: './event-list.html',
})
export class EventList {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);

  protected readonly searchIcon = Search;
  protected readonly whenIcon = CalendarDays;

  /** Stands in for a poster nobody has uploaded yet. */
  initial(event: { title: string }): string {
    return event.title.trim().charAt(0).toUpperCase() || '?';
  }
  protected readonly whereIcon = MapPin;

  readonly events = signal<EventSummary[]>([]);
  readonly loading = signal(true);

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
  readonly date = signal<DateChoice>('any');
  readonly pickedDate = signal('');
  readonly price = signal<PriceChoice>('all');
  readonly sort = signal<SortChoice>('soon');

  readonly sortOptions: SelectOption[] = [
    { value: 'soon', label: 'Soonest' },
    { value: 'price-asc', label: 'Price: low to high' },
    { value: 'price-desc', label: 'Price: high to low' },
  ];

  /** Only categories with something on — a filter leading nowhere is worse than none. */
  readonly categories = signal<{ category: string; events: number }[]>([]);

  readonly formatFrom = formatFrom;

  readonly anyFilter = computed(
    () =>
      this.query() !== '' ||
      this.city() !== '' ||
      this.category() !== '' ||
      this.date() !== 'any' ||
      this.price() !== 'all',
  );

  /** The date window and the paid-only case, applied over what has loaded. */
  readonly visible = computed(() => {
    let list = this.events();

    if (this.price() === 'paid') {
      list = list.filter((e) => e.from_price !== null && e.from_price.amount > 0);
    }

    const choice = this.date();
    if (choice !== 'any') {
      list = list.filter((e) => this.inDateWindow(e, choice));
    }

    const sort = this.sort();
    if (sort !== 'soon') {
      const amount = (e: EventSummary) => e.from_price?.amount ?? 0;
      list = [...list].sort((a, b) =>
        sort === 'price-asc' ? amount(a) - amount(b) : amount(b) - amount(a),
      );
    }

    return list;
  });

  constructor() {
    this.seo.forListing(
      'What is on',
      'Find events near you and get tickets in a couple of taps.',
      'https://myfiesta.ca/events',
    );

    this.api.discover().subscribe({
      next: (d) => this.categories.set(d.categories),
      error: () => undefined,
    });

    // Filters live in the URL so a filtered search can be shared and comes
    // back the same, and so the back button behaves.
    this.route.queryParamMap.subscribe((params) => {
      this.query.set(params.get('q') ?? '');
      this.city.set(params.get('city') ?? '');
      this.category.set(params.get('category') ?? '');
      this.price.set((params.get('price') as PriceChoice) ?? 'all');
      this.date.set((params.get('date') as DateChoice) ?? 'any');
      this.pickedDate.set(params.get('on') ?? '');
      this.load();
    });
  }

  search(): void {
    this.router.navigate([], {
      queryParams: {
        q: this.query() || null,
        city: this.city() || null,
        category: this.category() || null,
        price: this.price() === 'all' ? null : this.price(),
        date: this.date() === 'any' ? null : this.date(),
        on: this.date() === 'date' ? this.pickedDate() || null : null,
      },
      queryParamsHandling: 'merge',
    });
  }

  /** One at a time: the server takes a single category. Same one again clears it. */
  chooseCategory(name: string): void {
    this.category.set(this.category() === name ? '' : name);
    this.search();
  }

  chooseDate(choice: DateChoice): void {
    this.date.set(choice);
    if (choice !== 'date') this.pickedDate.set('');
    this.search();
  }

  choosePrice(choice: PriceChoice): void {
    this.price.set(this.price() === choice ? 'all' : choice);
    this.search();
  }

  clearAll(): void {
    this.query.set('');
    this.city.set('');
    this.category.set('');
    this.date.set('any');
    this.pickedDate.set('');
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

  private serverQuery() {
    return {
      q: this.query() || undefined,
      city: this.city() || undefined,
      category: this.category() || undefined,
      free: this.price() === 'free' || undefined,
    };
  }

  private load(): void {
    this.loading.set(true);

    this.api.events(this.serverQuery()).subscribe({
      next: (page) => {
        this.events.set(page.data);
        this.nextCursor.set(page.meta?.next_cursor ?? null);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  private inDateWindow(event: EventSummary, choice: DateChoice): boolean {
    // The event's calendar date in its own zone — a Lagos night on the 12th
    // is on the 12th, whoever is asking.
    const day = new Intl.DateTimeFormat('en-CA', {
      timeZone: event.timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(event.starts_at));

    const today = new Intl.DateTimeFormat('en-CA', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date());

    if (choice === 'today') return day === today;
    if (choice === 'date') return this.pickedDate() !== '' && day === this.pickedDate();

    // This weekend: the coming Friday through Sunday — or the one underway.
    const now = new Date();
    const dow = now.getDay(); // 0 Sun … 6 Sat
    const friday = new Date(now);
    friday.setDate(now.getDate() + ((5 - dow + 7) % 7) - (dow === 6 || dow === 0 ? 7 : 0));
    const sunday = new Date(friday);
    sunday.setDate(friday.getDate() + 2);

    const fmt = (d: Date) =>
      new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);

    return day >= fmt(friday) && day <= fmt(sunday);
  }

  when(event: EventSummary): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      // Rendered in the event's own zone, not the reader's. A Lagos event at
      // 10pm should say 10pm to someone browsing from Toronto.
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }
}
