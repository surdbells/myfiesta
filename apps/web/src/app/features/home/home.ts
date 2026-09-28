import { Component, computed, inject, signal } from '@angular/core';
import { LowerCasePipe, NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { UiIcon } from '@myfiesta/ui';
import {
  ArrowLeft,
  ArrowRight,
  CalendarDays,
  Flame,
  History,
  MapPin,
  Search,
  Sparkles,
  Ticket,
  type LucideIconData,
} from 'lucide-angular';
import { LucideAngularModule } from 'lucide-angular';
import { Api, When } from '../../core/api';
import { CONSOLE_URL } from '../../core/console-url';
import { Discovery, EventSummary } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { Seo } from '../../core/seo';
import { AppPromo } from '../../shared/app-promo';
import { AvailabilityBadge } from '../../shared/availability-badge';
import { categoryIcon } from '../../shared/category-art';
import { EventCard } from '../../shared/event-card';
import { OrganizerPitch } from '../../shared/organizer-pitch';
import { PosterArt } from '../../shared/poster-art';

/** A shelf of events with a reason for being a shelf, and a way to see all of them. */
export interface Shelf {
  readonly key: string;
  readonly title: string;
  readonly hint: string;
  readonly icon: LucideIconData;
  readonly events: EventSummary[];
  /** The listing, filtered to this shelf — where "See all" goes. */
  readonly all: Record<string, string>;
  /** Cards for nights that have happened: proof, not offers. */
  readonly past?: boolean;
}

/** More than this and a shelf scrolls sideways; fewer lay out as a grid. */
const FILLS_A_ROW = 4;

/** How long a night with no end is taken to last — the API's TurnedAway::HOURS_WITHOUT_AN_END. */
const HOURS_WITHOUT_AN_END = 12;

/** Names of the two markets, for the city cards. */
const COUNTRIES: Record<string, string> = { CA: 'Canada', NG: 'Nigeria' };

/**
 * The front page.
 *
 * There was not one: the root rendered the same flat search list as /events, so
 * somebody arriving without a link to a specific event had nothing to look at
 * and no way to browse.
 *
 * One request fills the whole page (/api/discover). This is the first thing a
 * stranger sees, on a phone, and ten round trips is ten chances to look broken.
 *
 * It sells two things. Tickets, to the person who came for them — the hero is
 * made of the posters of what is actually on, and every shelf below it is a
 * question somebody asks: what is on tonight, this weekend, what is about to
 * go. And the platform, to the organizer standing behind them, who almost
 * always arrives here as a buyer first.
 */
@Component({
  selector: 'app-home',
  imports: [
    RouterLink,
    FormsModule,
    NgTemplateOutlet,
    LowerCasePipe,
    EventCard,
    OrganizerPitch,
    AppPromo,
    UiIcon,
    AvailabilityBadge,
    PosterArt,
    LucideAngularModule,
  ],
  templateUrl: './home.html',
  styleUrl: './home.css',
})
export class Home {
  protected readonly arrowIcon = ArrowRight;
  protected readonly backIcon = ArrowLeft;
  protected readonly whereIcon = MapPin;
  protected readonly whenIcon = CalendarDays;
  protected readonly searchIcon = Search;
  protected readonly flameIcon = Flame;
  protected readonly ticketIcon = Ticket;

  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);

  readonly consoleUrl = inject(CONSOLE_URL);

  readonly discovery = signal<Discovery | null>(null);
  readonly loading = signal(true);
  readonly failed = signal(false);

  readonly query = signal('');
  readonly city = signal('');
  readonly when = signal<When | ''>('');

  readonly whenOptions: { value: When | ''; label: string }[] = [
    { value: '', label: 'Any time' },
    { value: 'today', label: 'Today' },
    { value: 'weekend', label: 'This weekend' },
    { value: 'month', label: 'This month' },
  ];

  /** One tap to the questions people most often come with. */
  readonly quickPicks: { label: string; params: Record<string, string> }[] = [
    { label: 'Tonight', params: { when: 'today' } },
    { label: 'This weekend', params: { when: 'weekend' } },
    { label: 'Free', params: { price: 'free' } },
    { label: 'Almost sold out', params: { availability: 'almost_sold_out' } },
  ];

  /**
   * Cities, with the country added only where the name alone is ambiguous.
   *
   * The API sends one card for each city page, every spelling counted under
   * one. An older answer grouped by city *and* country, and two cards reading
   * "Toronto", with different counts and no way to tell which is which, is
   * worse than either of them.
   */
  readonly cities = computed(() => {
    const raw = this.discovery()?.cities ?? [];
    const seen = new Map<string, number>();

    for (const place of raw) seen.set(place.city, (seen.get(place.city) ?? 0) + 1);

    return raw.map((place) => ({
      ...place,
      label: (seen.get(place.city) ?? 0) > 1 ? `${place.city}, ${place.country}` : place.city,
      countryName: COUNTRIES[place.country] ?? place.country,
    }));
  });

  /** Just the names, deduplicated, for the search's city picker. */
  readonly cityNames = computed(() => [...new Set(this.cities().map((c) => c.city))]);

  readonly categories = computed(() => (this.discovery()?.categories ?? []).slice(0, 8));

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
        this.seo.forHome('https://myfiesta.ca/', this.description(), [...(discovery.featured ?? []), ...(discovery.upcoming ?? [])]);
      },
      error: () => {
        // Not the empty state. "Nothing on sale" and "we could not reach the
        // server" look alike and mean opposite things.
        this.loading.set(false);
        this.failed.set(true);
      },
    });
  }

  // --- the hero --------------------------------------------------------------

  /** The lead event, given the hero. */
  readonly lead = computed<EventSummary | null>(
    () => this.discovery()?.featured[0] ?? this.discovery()?.upcoming[0] ?? null,
  );

  /**
   * The posters beside the lead: real nights on sale, posters first, each
   * once. On a phone they become a strip to swipe through.
   */
  readonly mosaic = computed<EventSummary[]>(() => {
    const discovery = this.discovery();
    const lead = this.lead();
    if (!discovery || !lead) return [];

    const pool = [
      ...discovery.featured,
      ...(discovery.today ?? []),
      ...(discovery.weekend ?? []),
      ...discovery.upcoming,
    ];
    const seen = new Set([lead.slug]);
    const unique = pool.filter((event) => {
      if (seen.has(event.slug)) return false;
      seen.add(event.slug);
      return true;
    });

    return [...unique.filter((e) => e.poster_url), ...unique.filter((e) => !e.poster_url)].slice(0, 4);
  });

  /**
   * The line above the headline: real counts, or nothing grand at all.
   * No number here is one the database cannot answer for.
   */
  readonly liveLine = computed(() => {
    const totals = this.discovery()?.totals;

    if (!totals || totals.upcoming === 0) return 'Tickets for the night out';

    const events = `${totals.upcoming} ${totals.upcoming === 1 ? 'event' : 'events'} coming up`;

    return totals.cities > 1 ? `${events} in ${totals.cities} cities` : events;
  });

  /** "Toronto, Lagos, Montreal and more" — whatever is actually busiest. */
  readonly placesLine = computed(() => {
    const names = this.cityNames();

    if (names.length === 0) return 'across Canada and Nigeria';
    if (names.length === 1) return `in ${names[0]}`;
    if (names.length <= 3) return `in ${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

    return `in ${names.slice(0, 3).join(', ')} and more`;
  });

  private description(): string {
    return `Tickets for concerts, club nights, comedy and festivals ${this.placesLine()}. Check out as a guest, and show your phone at the door.`;
  }

  // --- the shelves -----------------------------------------------------------

  /**
   * The shelves, in the order somebody scans them, each only when it has
   * something in it. "Coming up" leaves out what today and this weekend have
   * already shown, so a small catalogue does not read as the same four
   * nights under three headings.
   */
  readonly shelves = computed<{ today?: Shelf; weekend?: Shelf; almost?: Shelf; upcoming?: Shelf; soldOut?: Shelf; past?: Shelf }>(() => {
    const discovery = this.discovery();
    if (!discovery) return {};

    const shelf = (value: Shelf): Shelf | undefined => (value.events.length > 0 ? value : undefined);
    const shown = new Set([...(discovery.today ?? []), ...(discovery.weekend ?? [])].map((e) => e.slug));

    return {
      today: shelf({
        key: 'today',
        title: 'Happening today',
        hint: 'On now and later tonight, at each venue’s own time.',
        icon: Sparkles,
        events: discovery.today ?? [],
        all: { when: 'today' },
      }),
      weekend: shelf({
        key: 'weekend',
        title: 'This weekend',
        hint: 'Friday to Sunday — plan it now.',
        icon: CalendarDays,
        events: discovery.weekend ?? [],
        all: { when: 'weekend' },
      }),
      almost: shelf({
        key: 'almost',
        title: 'Almost sold out',
        hint: 'Going fast. Get in before they go.',
        icon: Flame,
        events: discovery.almost_sold_out ?? [],
        all: { availability: 'almost_sold_out' },
      }),
      upcoming: shelf({
        key: 'upcoming',
        title: 'Coming up',
        hint: 'Everything else on sale, soonest first.',
        icon: Ticket,
        // The API's own "later" — chosen in the query, so a busy weekend
        // cannot use up the dozen it sends — or, from an older API, upcoming
        // with today and the weekend taken out.
        events: discovery.later ?? discovery.upcoming.filter((e) => !shown.has(e.slug)),
        all: {},
      }),
      // Nights still to come, each taking names, and then ones that sold out
      // and have happened: each card is drawn as what it is (over()).
      soldOut: shelf({
        key: 'sold-out',
        title: 'Sold out',
        hint: 'Nights that went fast. For the ones still to come, the waitlist hears first if tickets come back.',
        icon: Ticket,
        events: discovery.sold_out ?? [],
        all: { availability: 'sold_out' },
      }),
      past: shelf({
        key: 'past',
        title: 'Recently',
        hint: 'It happened — see what you missed, and who to follow for the next one.',
        icon: History,
        events: discovery.past ?? [],
        all: { when: 'past' },
        past: true,
      }),
    };
  });

  readonly nothingOn = computed(() => {
    const shelves = this.shelves();

    return !shelves.today && !shelves.weekend && !shelves.upcoming && !shelves.almost;
  });

  scrolls(shelf: Shelf): boolean {
    return shelf.events.length > FILLS_A_ROW;
  }

  /**
   * A night that is over, by its own listing: its end, or half a day after it
   * starts when it has none — the API's rule (EventWindows). The sold-out
   * shelf holds nights on both sides of it, and one that happened last week
   * drawn as an offer reads as next week's.
   */
  over(event: EventSummary): boolean {
    const end = event.ends_at
      ? new Date(event.ends_at).getTime()
      : new Date(event.starts_at).getTime() + HOURS_WITHOUT_AN_END * 3_600_000;

    return end <= Date.now();
  }

  // --- small words -----------------------------------------------------------

  /** Short form for the hero cards: "Fri, Oct 2 · 9:00 p.m." in the venue's zone. */
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

  price(event: EventSummary): string {
    if (event.is_sold_out) return 'Sold out';
    if (event.availability?.state === 'closed') return 'Sales closed';
    if (!event.from_price) return 'Tickets soon';
    if (event.from_price.amount === 0) return 'Free';

    return `From ${formatMoney(event.from_price)}`;
  }

  iconFor(category: string | null): LucideIconData {
    return categoryIcon(category);
  }

  // --- going somewhere ---------------------------------------------------------

  /**
   * Hand the search to the listing, which is what owns searching.
   *
   * Empty fields are left out of the URL rather than sent blank, so a
   * bookmarked search says what it actually filtered on.
   */
  search(): void {
    const params: Record<string, string> = {};

    if (this.query().trim()) params['q'] = this.query().trim();
    if (this.city().trim()) params['city'] = this.city().trim();
    if (this.when()) params['when'] = this.when();

    void this.router.navigate(['/events'], { queryParams: params });
  }

  /** Scroll a shelf by most of its width, in either direction. */
  nudge(rail: HTMLElement, direction: -1 | 1): void {
    const reduce = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

    rail.scrollBy({ left: direction * rail.clientWidth * 0.85, behavior: reduce ? 'auto' : 'smooth' });
  }
}
