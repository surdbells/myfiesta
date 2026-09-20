import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  ToastStore,
  UiBadge,
  UiButton,
  UiEmpty,
  UiErrorState,
  UiIcon,
  UiPageHeader,
  UiPagination,
  UiSelect,
  UiSkeleton,
  UiDateRange,
  UiFilterBar,
  type DateRange,
  type FilterChip,
  type SelectOption,
} from '@myfiesta/ui';
import { Download, Search } from 'lucide-angular';
import { Subject, debounceTime, distinctUntilChanged, map, switchMap } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api } from '../../core/api';
import { EventOption, Money, OrderSignal, OrganizationOrder } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';
import { saveFile, today } from '../../core/download';

/** What each signal means, in the words an organizer would use. */
const SIGNALS: Record<OrderSignal, { label: string; hint: string }> = {
  disputed: {
    label: 'Charged back',
    hint: 'This buyer told their bank the charge was wrong. Their tickets stop working if the bank agrees.',
  },
  previous_chargeback: {
    label: 'Chargeback before',
    hint: 'This address won a chargeback against you once. Worth a look before the night.',
  },
  many_orders: {
    label: 'Several orders today',
    hint: 'More than four orders from this address in a day — usually somebody buying for a group.',
  },
};

/**
 * Every order the organization has taken.
 *
 * The console could already show orders, but only inside an event — which
 * answers "how did Friday go" and not the question support arrives with:
 * somebody on the phone with a reference, or an address, and nobody knows
 * which night they bought for.
 *
 * Read-only by design. Refunding stays on the event's own orders screen,
 * where the tickets being returned are listed beside the order; money goes
 * back one ticket at a time, and a list this wide is the wrong place to
 * start that. Each row links there.
 */
@Component({
  selector: 'app-orders',
  imports: [
    FormsModule,
    RouterLink,
    UiPageHeader,
    UiBadge,
    UiButton,
    UiEmpty,
    UiErrorState,
    UiIcon,
    UiPagination,
    UiSelect,
    UiSkeleton,
    UiFilterBar,
    UiDateRange,
  ],
  templateUrl: './orders.html',
})
export class Orders {
  readonly signals = SIGNALS;

  protected readonly searchIcon = Search;
  protected readonly downloadIcon = Download;

  private readonly toasts = inject(ToastStore);
  readonly exporting = signal(false);

  private readonly api = inject(Api);
  readonly session = inject(SessionStore);

  readonly orders = signal<OrganizationOrder[]>([]);
  readonly meta = signal<{ total: number; per_page: number; current_page: number } | null>(null);
  readonly takings = signal<{ gross: Money; refunded: Money; net: Money } | null>(null);

  readonly loading = signal(true);
  readonly failed = signal(false);
  /** A 403 is not a failure to load; it is an answer. */
  readonly refused = signal(false);

  readonly query = signal('');
  readonly eventId = signal('');
  readonly status = signal('');
  /** When it happened. Both ends inclusive, and either may be open. */
  readonly range = signal<DateRange>({ from: null, to: null });
  readonly page = signal(1);

  /** The filter's options, so somebody can narrow to one night. */
  readonly events = signal<EventOption[]>([]);

  /** Every event, most recent first, with its date to tell repeats of a night apart. */
  readonly eventOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'All events' },
    ...this.events().map((event) => ({
      value: event.id,
      label: event.title,
      hint: new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(event.starts_at)),
    })),
  ]);

  readonly statusOptions: SelectOption[] = [
    { value: '', label: 'Any status' },
    { value: 'paid', label: 'Paid' },
    { value: 'partially_refunded', label: 'Part refunded' },
    { value: 'refunded', label: 'Refunded' },
    { value: 'pending', label: 'Confirming' },
  ];

  readonly filtered = computed(() => this.chips().length > 0);

  /**
   * What is on, as chips.
   *
   * Each one carries the label it was chosen by rather than the field name:
   * somebody removing "Event: Afro Fest" is not thinking about event_id.
   */
  readonly chips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const { from, to } = this.range();

    if (this.query()) chips.push({ key: 'q', label: 'Search', value: this.query() });

    if (this.eventId()) {
      const event = this.events().find((option) => option.id === this.eventId());
      chips.push({ key: 'event', label: 'Event', value: event?.title ?? 'One event' });
    }

    if (this.status()) {
      const status = this.statusOptions.find((option) => option.value === this.status());
      chips.push({ key: 'status', label: 'Status', value: status?.label ?? this.status() });
    }

    if (from || to) {
      const day = (value: string | null) =>
        value ? new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' }).format(new Date(value)) : null;

      chips.push({
        key: 'range',
        label: 'When',
        value: from && to ? `${day(from)} – ${day(to)}` : from ? `From ${day(from)}` : `Until ${day(to)}`,
      });
    }

    return chips;
  });

  /**
   * What is on screen against what the filter matches.
   *
   * Said always, because a filtered table that reads like an unfiltered one is
   * how somebody takes a week's takings for the year's.
   */
  readonly summary = computed(() => {
    const meta = this.meta();
    if (!meta) return null;

    const noun = meta.total === 1 ? 'order' : 'orders';
    const shown = Math.min(meta.per_page, this.orders().length);

    return meta.total > shown
      ? `Showing ${shown} of ${meta.total.toLocaleString()} ${noun}`
      : `${meta.total.toLocaleString()} ${noun}`;
  });

  /** Take one filter off, by the key its chip carries. */
  remove(key: string): void {
    if (key === 'q') this.query.set('');
    if (key === 'event') this.eventId.set('');
    if (key === 'status') this.status.set('');
    if (key === 'range') this.range.set({ from: null, to: null });

    this.refine();
  }

  private readonly requests = new Subject<void>();

  constructor() {
    // Typing runs a search, but not on every keystroke — and never twice for
    // the same terms, which is what a backspace-and-retype produces.
    this.requests
      .pipe(
        debounceTime(250),
        // Serialised on the query, not the void: two searches in flight can
        // land out of order and leave the slower one's rows on screen.
        map(() => JSON.stringify([this.query(), this.eventId(), this.status(), this.range(), this.page()])),
        distinctUntilChanged(),
        switchMap(() =>
          this.api.organizationOrders({
            q: this.query(),
            event_id: this.eventId(),
            status: this.status(),
            from: this.range().from ?? '',
            to: this.range().to ?? '',
            page: this.page(),
          }),
        ),
        takeUntilDestroyed(),
      )
      .subscribe({
        next: (result) => {
          this.orders.set(result.data);
          this.meta.set(result.meta);
          this.takings.set(result.meta.summary);
          this.loading.set(false);
        },
        error: (response) => {
          this.loading.set(false);
          if (response?.status === 403) {
            this.refused.set(true);
          } else {
            this.failed.set(true);
          }
        },
      });

    this.api.eventOptions().subscribe({
      next: ({ data }) => this.events.set(data),
      error: () => undefined,
    });

    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.requests.next();
  }

  /** Any change to the terms starts again at the first page. */
  refine(): void {
    this.page.set(1);
    this.load();
  }

  goToPage(page: number): void {
    this.page.set(page);
    this.load();
  }

  /**
   * Every order the current filter matches, as a spreadsheet — not the page on
   * screen. Filtered exactly as the list is, so the file never holds a
   * different set of orders from the one being looked at.
   */
  export(): void {
    if (this.exporting()) return;

    this.exporting.set(true);

    this.api
      .exportOrders({
        q: this.query(),
        event_id: this.eventId(),
        status: this.status(),
        from: this.range().from ?? '',
        to: this.range().to ?? '',
      })
      .subscribe({
        next: (file) => {
          this.exporting.set(false);
          saveFile(file, `myfiesta-orders-${today()}.csv`);
        },
        error: () => {
          this.exporting.set(false);
          this.toasts.show('The export could not be downloaded. Try again.', 'danger');
        },
      });
  }

  clear(): void {
    this.query.set('');
    this.eventId.set('');
    this.status.set('');
    this.range.set({ from: null, to: null });
    this.refine();
  }

  cash(money: Money): string {
    return formatMoney(money);
  }

  label(order: OrganizationOrder): string {
    if (order.status === 'refunded') return 'Refunded';
    if (order.status === 'partially_refunded') return 'Part refunded';
    if (order.status === 'pending') return 'Confirming';

    return 'Paid';
  }

  tone(order: OrganizationOrder): 'neutral' | 'success' | 'warning' | 'danger' {
    if (order.status === 'refunded') return 'danger';
    // Neither paid nor refunded, and reading it as either is how the rest of
    // an order gets refunded by accident.
    if (order.status === 'partially_refunded') return 'warning';
    if (order.status === 'pending') return 'neutral';

    return 'success';
  }

  when(iso: string | null): string {
    if (!iso) return '—';

    return new Intl.DateTimeFormat('en-CA', {
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(iso));
  }
}
