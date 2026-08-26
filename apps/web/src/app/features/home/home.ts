import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { CONSOLE_URL } from '../../core/console-url';
import { Discovery, EventSummary } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { EventCard } from '../../shared/event-card';
import { OrganizerPitch } from '../../shared/organizer-pitch';

/** A row of events with a reason for being a row. */
interface Rail {
  readonly title: string;
  readonly hint?: string;
  readonly events: EventSummary[];
}

/**
 * The front page.
 *
 * There was not one: the root rendered the same flat search list as /events, so
 * somebody arriving without a link to a specific event had nothing to look at
 * and no way to browse.
 *
 * One request fills the whole page. This is the first thing a stranger sees, on
 * a phone, and four round trips is four chances to look broken.
 *
 * It sells two things. Tickets, to the person who came for them; and the
 * platform, to the organizer standing behind them — who almost always arrives
 * here as a buyer first, and who had nothing to read on this page at all.
 */
@Component({
  selector: 'app-home',
  imports: [RouterLink, FormsModule, EventCard, OrganizerPitch],
  templateUrl: './home.html',
  styleUrl: './home.css',
})
export class Home {
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  readonly consoleUrl = inject(CONSOLE_URL);

  readonly discovery = signal<Discovery | null>(null);
  readonly loading = signal(true);
  readonly failed = signal(false);

  readonly query = signal('');
  readonly city = signal('');

  /**
   * Cities, with the country added only where the name alone is ambiguous.
   *
   * The API groups by city *and* country, so a name that exists in both
   * markets comes back twice — and two chips reading "Toronto", with different
   * counts and no way to tell which is which, is worse than either of them.
   * The country is shown on both members of a clash and on neither otherwise,
   * because "Ottawa, CA" everywhere is noise on a site that mostly serves one
   * country at a time.
   */
  readonly cities = computed(() => {
    const raw = this.discovery()?.cities ?? [];

    const seen = new Map<string, number>();

    for (const place of raw) {
      seen.set(place.city, (seen.get(place.city) ?? 0) + 1);
    }

    return raw.map((place) => ({
      ...place,
      label: (seen.get(place.city) ?? 0) > 1 ? `${place.city}, ${place.country}` : place.city,
    }));
  });
  /**
   * Just the names, deduplicated, for the search field.
   *
   * The datalist is a picker: two identical options in it is a list that
   * looks broken, and picking either one filters by the same name anyway.
   */
  readonly cityNames = computed(() => [...new Set(this.cities().map((c) => c.city))]);

  readonly categories = computed(() => this.discovery()?.categories ?? []);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);

    this.api.discover().subscribe({
      next: (discovery) => {
        this.discovery.set(discovery);
        this.loading.set(false);
      },
      error: () => {
        // Not the empty state. "Nothing on sale" and "we could not reach the
        // server" look alike and mean opposite things.
        this.loading.set(false);
        this.failed.set(true);
      },
    });
  }

  /** The lead event, given the hero. */
  readonly lead = computed<EventSummary | null>(
    () => this.discovery()?.featured[0] ?? this.discovery()?.upcoming[0] ?? null,
  );

  /**
   * The rows, in the order somebody scans them.
   *
   * Built rather than hard-coded because a small catalogue would otherwise
   * show the same four events under three different headings, which reads as
   * a fault. A rail with nothing in it is not rendered at all.
   */
  readonly rails = computed<Rail[]>(() => {
    const discovery = this.discovery();

    if (!discovery) return [];

    const shown = new Set<string>();
    const lead = this.lead();

    if (lead) shown.add(lead.slug);

    const take = (events: EventSummary[]) =>
      events.filter((event) => {
        if (shown.has(event.slug)) return false;

        shown.add(event.slug);

        return true;
      });

    const week = Date.now() + 7 * 24 * 60 * 60 * 1000;

    const rails: Rail[] = [
      {
        title: 'This week',
        hint: 'On in the next seven days',
        events: take(
          discovery.upcoming.filter((e) => new Date(e.starts_at).getTime() < week),
        ),
      },
      { title: 'Featured', events: take(discovery.featured) },
      { title: 'Coming up', events: take(discovery.upcoming) },
    ];

    return rails.filter((rail) => rail.events.length > 0);
  });

  when(event: EventSummary): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      hour: 'numeric',
      minute: '2-digit',
      // The venue's zone, never the reader's. A Lagos event says 10pm to
      // somebody reading in Toronto.
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  price(event: EventSummary): string {
    if (event.is_sold_out) return 'Sold out';
    if (!event.from_price) return 'Free — get a ticket';

    return `Tickets from ${formatMoney(event.from_price)}`;
  }

  /**
   * Hand the search to the listing screen, which is what owns searching.
   *
   * Empty fields are left out of the URL rather than sent blank, so a
   * bookmarked search says what it actually filtered on.
   */
  search(): void {
    const params: Record<string, string> = {};

    if (this.query().trim()) params['q'] = this.query().trim();
    if (this.city().trim()) params['city'] = this.city().trim();

    void this.router.navigate(['/events'], { queryParams: params });
  }

  browseCity(city: string): void {
    void this.router.navigate(['/events'], { queryParams: { city } });
  }

  browseCategory(category: string): void {
    void this.router.navigate(['/events'], { queryParams: { category } });
  }
}
