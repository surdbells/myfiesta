import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  UiBadge,
  UiButton,
  UiColumnMenu,
  UiDateRange,
  UiEmpty,
  UiErrorState,
  UiFilterBar,
  UiIcon,
  UiPageHeader,
  UiPagination,
  UiScrollRegion,
  UiSelect,
  UiSkeleton,
  UiSortHeader,
  UiSparkline,
  createListState,
  rangeZone,
  type DateRange,
  type FilterChip,
  type SelectOption,
} from '@myfiesta/ui';
import { LayoutGrid, Plus, Rows3, TrendingDown, TrendingUp } from 'lucide-angular';
import { Api, type ListQuery } from '../../core/api';
import { EventTrend, OrganizerEvent } from '../../core/api.types';
import { eventDate, shortEventTime } from '../../core/event-time';
import { loadList, searchBox } from '../../core/list-loader';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';
import { SavedViews } from '../../shared/saved-views';
import { eventStatusLabel } from './event-status';

type Layout = 'table' | 'cards';

const LAYOUT_KEY = 'myfiesta.list.events.layout';

const STATUSES: SelectOption[] = [
  { value: 'published', label: 'On sale' },
  { value: 'in_review', label: 'In review' },
  { value: 'draft', label: 'Draft' },
  { value: 'cancelled', label: 'Cancelled' },
];

/** Which nights: the three tabs above the list. '' is every night, upcoming first. */
const WHEN: readonly { value: '' | 'upcoming' | 'past'; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'past', label: 'Past' },
];

/**
 * The organization's events, read for what needs doing today.
 *
 * Above the list, the whole filtered set in five figures: how many nights,
 * how much of the room is gone, what it earned, which have gone quiet and
 * which are nearly full — the last two are the nights somebody should open
 * first. Then the nights themselves, as a dense table to compare them (sold
 * against the room, sell-through, earnings, the last fortnight day by day
 * with this week against the last, how many who looked bought, when the last
 * ticket went) or as cards to scan by poster. Filters live in the address and
 * can be kept as views; the layout is remembered by this browser.
 */
@Component({
  selector: 'app-event-list',
  imports: [
    RouterLink,
    SavedViews,
    UiBadge,
    UiButton,
    UiColumnMenu,
    UiDateRange,
    UiEmpty,
    UiErrorState,
    UiFilterBar,
    UiIcon,
    UiPageHeader,
    UiPagination,
    UiScrollRegion,
    UiSelect,
    UiSkeleton,
    UiSortHeader,
    UiSparkline,
  ],
  templateUrl: './event-list.html',
})
export class EventList {
  private readonly api = inject(Api);
  readonly session = inject(SessionStore);

  readonly when = shortEventTime;
  readonly onDate = eventDate;
  readonly cash = formatMoney;
  readonly statusLabel = eventStatusLabel;

  readonly whenTabs = WHEN;
  readonly statusOptions = STATUSES;

  protected readonly plusIcon = Plus;
  protected readonly tableIcon = Rows3;
  protected readonly cardsIcon = LayoutGrid;
  protected readonly upIcon = TrendingUp;
  protected readonly downIcon = TrendingDown;

  readonly list = createListState({
    list: 'events',
    filters: {
      when: { kind: 'one' },
      q: { kind: 'text' },
      status: { kind: 'many' },
      city: { kind: 'many' },
      from: { kind: 'day' },
      to: { kind: 'day' },
    },
    // Null: the server orders by the tab — upcoming soonest first, past most
    // recent first — until somebody sorts by a column.
    sort: null,
    columns: [
      { id: 'event', label: 'Event', required: true },
      { id: 'sold', label: 'Sold' },
      { id: 'sell_through', label: 'Sell-through' },
      { id: 'revenue', label: 'Earned' },
      { id: 'trend', label: 'Last 14 days' },
      { id: 'conversion', label: 'Looked and bought' },
      { id: 'last_sale', label: 'Last sale' },
      { id: 'arrived', label: 'Arrived', hidden: true },
    ],
  });

  readonly search = searchBox(this.list, 'q');

  private readonly question = computed<ListQuery>(() => {
    const query = this.list.query();
    return query['from'] || query['to'] ? { ...query, timezone: rangeZone() } : query;
  });

  readonly page = loadList(this.question, () => this.api.events(this.question()));

  readonly events = computed<OrganizerEvent[]>(() => this.page.result()?.data ?? []);
  readonly meta = computed(() => this.page.result()?.meta ?? null);
  readonly portfolio = computed(() => this.page.result()?.summary ?? null);
  readonly loading = computed(() => this.page.loading() && this.page.result() === null);
  readonly refreshing = computed(() => this.page.loading() && this.page.result() !== null);
  readonly failed = this.page.failed;

  readonly cityOptions = computed<SelectOption[]>(() => (this.page.result()?.cities ?? []).map((city) => ({ value: city, label: city })));

  readonly filtered = computed(() => {
    const values = this.list.values();
    return Object.entries(values).some(([key, value]) => key !== 'when' && (Array.isArray(value) ? value.length > 0 : !!value));
  });

  readonly range = computed<DateRange>(() => ({
    from: (this.list.get('from') as string | null) ?? null,
    to: (this.list.get('to') as string | null) ?? null,
  }));

  readonly layout = signal<Layout>(readLayout());

  /** Whether money can be shown at all: the server sends null to somebody who may not see it. */
  readonly showsMoney = computed(() => this.portfolio()?.revenue !== null && this.portfolio()?.revenue !== undefined);

  readonly chips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const values = this.list.values();

    if (values.q) chips.push({ key: 'q', label: 'Search', value: String(values.q) });

    const statuses = values.status as readonly string[];
    if (statuses.length > 0) {
      chips.push({ key: 'status', label: 'Status', value: statuses.map((s) => STATUSES.find((o) => o.value === s)?.label ?? s).join(' or ') });
    }

    const cities = values.city as readonly string[];
    if (cities.length > 0) chips.push({ key: 'city', label: cities.length === 1 ? 'City' : 'Cities', value: cities.join(' or ') });

    const { from, to } = this.range();
    if (from || to) {
      const day = (value: string | null) =>
        value ? new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(value)) : null;
      chips.push({ key: 'range', label: 'On', value: from && to ? `${day(from)} – ${day(to)}` : from ? `From ${day(from)}` : `Until ${day(to)}` });
    }

    return chips;
  });

  readonly summary = computed(() => {
    const meta = this.meta();
    if (!meta) return null;

    const noun = meta.total === 1 ? 'event' : 'events';
    return `${meta.total.toLocaleString()} ${noun}`;
  });

  /** A card list sorts from one control rather than column headings. */
  readonly sortOptions: SelectOption[] = [
    { value: '', label: 'By date' },
    { value: 'sold:desc', label: 'Most sold' },
    { value: 'revenue:desc', label: 'Most earned' },
    { value: 'sell_through:desc', label: 'Fullest' },
    { value: 'sell_through:asc', label: 'Emptiest' },
    { value: 'last_sale:asc', label: 'Quietest' },
    { value: 'title:asc', label: 'Name, A to Z' },
  ];

  readonly sortValue = computed(() => {
    const sort = this.list.sort();
    return sort ? `${sort.column}:${sort.direction}` : '';
  });

  sortBy(value: string | null): void {
    if (!value) {
      this.list.sortBy(null);
      return;
    }

    const [column, direction] = value.split(':');
    this.list.sortBy({ column, direction: direction === 'asc' ? 'asc' : 'desc' });
  }

  setWhen(value: string): void {
    this.list.set('when', value);
    // The tab's own order: soonest first ahead, most recent first behind.
    this.list.sortBy(null);
  }

  setRange(range: DateRange): void {
    this.list.set('from', range.from);
    this.list.set('to', range.to);
  }

  remove(key: string): void {
    if (key === 'range') this.setRange({ from: null, to: null });
    else this.list.clear(key as 'q');
  }

  clearFilters(): void {
    const when = this.list.get('when');
    this.list.clearAll();
    this.list.set('when', when);
  }

  setLayout(layout: Layout): void {
    this.layout.set(layout);
    try {
      localStorage.setItem(LAYOUT_KEY, layout);
    } catch {
      // Kept for this visit only.
    }
  }

  load(): void {
    this.page.retry();
  }

  // --- reading one night ----------------------------------------------------

  isPast(event: OrganizerEvent): boolean {
    return new Date(event.starts_at).getTime() < Date.now();
  }

  /**
   * The colour a status is allowed to be.
   *
   * Published is the working state and gets no colour at all — a list where
   * every row is green says nothing. The two that are worth a glance are the
   * ones that mean the event is not selling. Waiting for review is on its
   * way, not stuck, so it is the brand colour rather than a warning.
   */
  statusTone(status: OrganizerEvent['status']): 'neutral' | 'brand' | 'warning' | 'danger' {
    return status === 'draft' ? 'warning' : status === 'cancelled' ? 'danger' : status === 'in_review' ? 'brand' : 'neutral';
  }

  /** How much of the room has gone, where the room has a size. */
  sold(event: OrganizerEvent): number | null {
    if (!event.capacity) return null;

    return Math.min(1, event.tickets_issued / event.capacity);
  }

  /**
   * How far through the door an event is. Only once tickets exist: a
   * confident 0% for a night that sold nothing is a wrong number.
   */
  arrivalRate(event: OrganizerEvent): number | null {
    if (event.tickets_issued === 0) return null;

    return Math.round((event.checked_in / event.tickets_issued) * 100);
  }

  /**
   * How long until the doors, in the words somebody would say it: "in 3
   * months", "tomorrow", "tonight". The difference between those is the
   * whole reason this screen is opened.
   */
  countdown(event: OrganizerEvent): string | null {
    const hours = (new Date(event.starts_at).getTime() - Date.now()) / 3_600_000;

    if (hours < 0) return null;
    if (hours < 6) return 'Doors soon';
    if (hours < 24) return 'Tonight';
    if (hours < 48) return 'Tomorrow';

    const days = Math.round(hours / 24);
    if (days < 14) return `In ${days} days`;
    if (days < 60) return `In ${Math.round(days / 7)} weeks`;

    return `In ${Math.round(days / 30)} months`;
  }

  /**
   * When the last ticket sold, for a night still to come. "Nothing since
   * Tuesday" is a prompt to do something; about a night that already
   * happened it is noise.
   */
  lastSale(event: OrganizerEvent): string | null {
    if (!event.last_sale_at || this.isPast(event)) return null;

    const hours = (Date.now() - new Date(event.last_sale_at).getTime()) / 3_600_000;

    if (hours < 1) return 'In the last hour';
    if (hours < 24) return `${Math.round(hours)}h ago`;

    const days = Math.round(hours / 24);
    return days === 1 ? 'Yesterday' : `${days} days ago`;
  }

  /** On sale, still to come, and nothing for a week: worth flagging rather than stating. */
  stalled(event: OrganizerEvent): boolean {
    if (!event.last_sale_at || event.status !== 'published' || this.isPast(event)) return false;

    return (Date.now() - new Date(event.last_sale_at).getTime()) / 86_400_000 >= 7;
  }

  /** Of the people who looked, how many bought. Null before views were counted. */
  conversion(event: OrganizerEvent): number | null {
    return event.views ? event.orders / event.views : null;
  }

  percent(rate: number | null): string {
    return rate === null ? '—' : `${(rate * 100).toFixed(rate > 0 && rate < 0.1 ? 1 : 0)}%`;
  }

  trendOf(event: OrganizerEvent): EventTrend | null {
    return event.trend ?? null;
  }

  trendLabel(trend: EventTrend): string {
    const total = trend.days.reduce((sum, n) => sum + n, 0);
    return `${total} ${total === 1 ? 'ticket' : 'tickets'} in the last 14 days, ${trend.this_week} this week`;
  }

  /** "+40%", "−12%", or null when there is no week before to compare with. */
  momentum(trend: EventTrend): { text: string; up: boolean } | null {
    if (trend.momentum === null) return null;

    const value = Math.round(trend.momentum * 100);
    return { text: `${value > 0 ? '+' : value < 0 ? '−' : ''}${Math.abs(value)}%`, up: value >= 0 };
  }

  moneyList(): string {
    const revenue = this.portfolio()?.revenue ?? [];
    return revenue.length === 0 ? formatMoney({ amount: 0, currency: 'CAD' }) : revenue.map((money) => formatMoney(money)).join(' · ');
  }
}

function readLayout(): Layout {
  try {
    const stored = localStorage.getItem(LAYOUT_KEY);
    return stored === 'cards' ? 'cards' : 'table';
  } catch {
    return 'table';
  }
}
