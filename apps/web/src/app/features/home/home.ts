import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { CONSOLE_URL } from '../../core/console-url';
import { Discovery, EventSummary } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { ArrowLeft, ArrowRight, BadgeCheck, Search, ShieldCheck } from 'lucide-angular';
import { UiIcon } from '@myfiesta/ui';
import { EventCard } from '../../shared/event-card';
import { OrganizerPitch } from '../../shared/organizer-pitch';

/** A row of events with a reason for being a row. */
interface Rail {
  readonly title: string;
  readonly hint?: string;
  readonly events: EventSummary[];
  /**
   * Whether this row scrolls sideways or simply fills.
   *
   * A scrolling rail with two cards in it is 80% empty track, and reads as
   * a page that failed to load rather than as a row with more beyond the
   * edge. Below the point where a rail would actually overflow, the same
   * events lay out as a grid instead.
   */
  readonly scrolls: boolean;
  /** One or two events: the horizontal card, filling the row it was given. */
  readonly wide: boolean;
}

/** Roughly what fits on a wide screen before a row needs to scroll. */
const FILLS_A_ROW = 5;

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
  imports: [RouterLink, FormsModule, EventCard, OrganizerPitch, UiIcon],
  templateUrl: './home.html',
  styleUrl: './home.css',
})
export class Home {
  protected readonly arrowIcon = ArrowRight;
  protected readonly backIcon = ArrowLeft;
  protected readonly verifiedIcon = BadgeCheck;
  protected readonly secureIcon = ShieldCheck;
  protected readonly searchIcon = Search;

  private readonly api = inject(Api);
  private readonly router = inject(Router);

  readonly consoleUrl = inject(CONSOLE_URL);

  readonly discovery = signal<Discovery | null>(null);
  readonly loading = signal(true);
  readonly failed = signal(false);

  readonly query = signal('');
  readonly city = signal('');
  readonly on = signal('');

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

  /** The two events beside the lead in the hero collage. */
  readonly sideEvents = computed<EventSummary[]>(() => {
    const discovery = this.discovery();
    const lead = this.lead();
    if (!discovery || !lead) return [];

    const pool = [...discovery.featured, ...discovery.upcoming];
    const seen = new Set([lead.slug]);

    return pool
      .filter((e) => {
        if (seen.has(e.slug)) return false;
        seen.add(e.slug);
        return true;
      })
      .slice(0, 2);
  });

  /** Short form for the collage chips: "Fri, Aug 28 · 6:00 p.m." */
  chipWhen(event: EventSummary): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  /**
   * A ground for a category tile. There are no category photographs, so the
   * tiles cycle through the brand's own gradients — the same few, in order,
   * so the row reads as a designed set rather than a random one.
   */
  tileBg(index: number): string {
    const grounds = [
      'linear-gradient(135deg, var(--color-brand-600), var(--color-brand-900))',
      'linear-gradient(135deg, var(--color-gold-400), var(--color-brand-700))',
      'linear-gradient(135deg, var(--color-brand-400), var(--color-brand-800))',
      'linear-gradient(135deg, var(--color-neutral-800), var(--color-brand-900))',
    ];

    return grounds[index % grounds.length];
  }

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

    const rails = [
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

    return rails
      .filter((rail) => rail.events.length > 0)
      .map((rail) => ({
        ...rail,
        scrolls: rail.events.length > FILLS_A_ROW,
        wide: rail.events.length <= 2,
      }));
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
    if (this.on()) {
      params['date'] = 'date';
      params['on'] = this.on();
    }

    void this.router.navigate(['/events'], { queryParams: params });
  }

  /** Scroll a rail by most of a viewport, in either direction. */
  nudge(rail: HTMLElement, direction: -1 | 1): void {
    rail.scrollBy({ left: direction * rail.clientWidth * 0.8, behavior: 'smooth' });
  }

  browseCity(city: string): void {
    void this.router.navigate(['/events'], { queryParams: { city } });
  }

  browseCategory(category: string): void {
    void this.router.navigate(['/events'], { queryParams: { category } });
  }
}
