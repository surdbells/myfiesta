import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  Selection,
  ToastStore,
  UiAmountRange,
  UiBadge,
  UiBulkBar,
  UiButton,
  UiColumnMenu,
  UiDateRange,
  UiEmpty,
  UiErrorState,
  UiFilterBar,
  UiIcon,
  UiPageHeader,
  UiPagination,
  UiSelect,
  UiSortHeader,
  UiTable,
  createListState,
  rangeZone,
  type AmountRange,
  type DateRange,
  type FilterChip,
  type SelectOption,
  type Sort,
} from '@myfiesta/ui';
import { Download } from 'lucide-angular';
import { Api, type ListQuery } from '../../core/api';
import { EventOption, Money, OrderSignal, OrganizationOrder } from '../../core/api.types';
import { saveFile, today } from '../../core/download';
import { loadList, searchBox } from '../../core/list-loader';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';
import { SavedViews } from '../../shared/saved-views';

/**
 * What a signal on an order means, in the words a door manager would use.
 *
 * Shown as a label with its meaning on hover, never as a colour alone: a
 * badge nobody can explain is a reason to refuse somebody at the door.
 */
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

const STATUSES: SelectOption[] = [
  { value: 'paid', label: 'Paid' },
  { value: 'partially_refunded', label: 'Part refunded' },
  { value: 'refunded', label: 'Refunded' },
  { value: 'pending', label: 'Confirming' },
];

/**
 * Every order across the organization's events.
 *
 * The screen support opens with a reference read over the phone, and finance
 * opens to reconcile a week against the bank. So: search by reference, name
 * or address; filter by event, status, when and how much, several of each at
 * once; sort by any column; keep the combination as a saved view; tick rows
 * and export only those. The list lives in the address, so the link to "this
 * week's refunds" is a link to exactly that.
 */
@Component({
  selector: 'app-orders',
  imports: [
    FormsModule,
    RouterLink,
    SavedViews,
    UiAmountRange,
    UiBadge,
    UiBulkBar,
    UiButton,
    UiColumnMenu,
    UiDateRange,
    UiEmpty,
    UiErrorState,
    UiFilterBar,
    UiIcon,
    UiPageHeader,
    UiPagination,
    UiSelect,
    UiSortHeader,
    UiTable,
  ],
  templateUrl: './orders.html',
})
export class Orders {
  readonly signals = SIGNALS;
  readonly statusOptions = STATUSES;

  protected readonly downloadIcon = Download;

  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  readonly session = inject(SessionStore);

  readonly list = createListState({
    list: 'orders',
    filters: {
      q: { kind: 'text' },
      event_id: { kind: 'many' },
      status: { kind: 'many' },
      from: { kind: 'day' },
      to: { kind: 'day' },
      min_total: { kind: 'int' },
      max_total: { kind: 'int' },
    },
    sort: { column: 'paid_at', direction: 'desc' },
    columns: [
      { id: 'buyer', label: 'Buyer', required: true },
      { id: 'event', label: 'Event' },
      { id: 'reference', label: 'Reference' },
      { id: 'when', label: 'When' },
      { id: 'total', label: 'Total' },
      { id: 'status', label: 'Status' },
    ],
  });

  readonly selection = new Selection();

  /**
   * The list's query, with the zone its days are days in.
   *
   * The picker counts days on the reader's calendar; a yyyy-mm-dd sent alone
   * is a day in Greenwich to the server, which for a Toronto organizer is a
   * "Today" that ends in the early evening.
   */
  private readonly question = computed<ListQuery>(() => withZone(this.list.query()));

  readonly page = loadList(this.question, () => this.api.organizationOrders(this.question()));

  readonly orders = computed<OrganizationOrder[]>(() => this.page.result()?.data ?? []);
  readonly meta = computed(() => this.page.result()?.meta ?? null);
  readonly takings = computed(() => this.meta()?.summary ?? null);

  readonly loading = computed(() => this.page.loading() && this.page.result() === null);
  readonly refreshing = computed(() => this.page.loading() && this.page.result() !== null);
  readonly failed = this.page.failed;
  readonly refused = this.page.refused;
  readonly exporting = signal(false);

  readonly events = signal<EventOption[]>([]);

  readonly eventOptions = computed<SelectOption[]>(() =>
    this.events().map((event) => ({
      value: event.id,
      label: event.title,
      hint: new Intl.DateTimeFormat('en-CA', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(event.starts_at)),
    })),
  );

  /** The search box shows what is typed at once; the list follows a moment later. */
  readonly search = searchBox(this.list, 'q');

  readonly range = computed<DateRange>(() => ({
    from: (this.list.get('from') as string | null) ?? null,
    to: (this.list.get('to') as string | null) ?? null,
  }));

  readonly amounts = computed<AmountRange>(() => ({
    min: this.list.get('min_total') as number | null,
    max: this.list.get('max_total') as number | null,
  }));

  /** The currency amounts are typed in: the one this list is in. */
  readonly currency = computed(() => this.takings()?.gross.currency ?? this.orders()[0]?.total.currency ?? 'CAD');

  readonly rowIds = computed(() => this.orders().map((order) => order.id));

  readonly filtered = computed(() => this.list.active() > 0);


  readonly chips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const values = this.list.values();

    if (values.q) chips.push({ key: 'q', label: 'Search', value: String(values.q) });

    const events = values.event_id as readonly string[];
    if (events.length > 0) {
      const names = events.map((id) => this.events().find((e) => e.id === id)?.title ?? 'One event');
      chips.push({ key: 'event_id', label: events.length === 1 ? 'Event' : 'Events', value: listed(names) });
    }

    const statuses = values.status as readonly string[];
    if (statuses.length > 0) {
      chips.push({ key: 'status', label: 'Status', value: listed(statuses.map((s) => STATUSES.find((o) => o.value === s)?.label ?? s)) });
    }

    const { from, to } = this.range();
    if (from || to) {
      const day = (value: string | null) =>
        value ? new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(value)) : null;

      chips.push({
        key: 'range',
        label: 'When',
        value: from && to ? `${day(from)} – ${day(to)}` : from ? `From ${day(from)}` : `Until ${day(to)}`,
      });
    }

    const { min, max } = this.amounts();
    if (min !== null || max !== null) {
      const money = (amount: number) => formatMoney({ amount, currency: this.currency() as Money['currency'] });
      chips.push({
        key: 'amount',
        label: 'Total',
        value: min !== null && max !== null ? `${money(min)} – ${money(max)}` : min !== null ? `${money(min)} or more` : `Up to ${money(max!)}`,
      });
    }

    return chips;
  });

  readonly summary = computed(() => {
    const meta = this.meta();
    if (!meta) return null;

    const noun = meta.total === 1 ? 'order' : 'orders';
    const shown = Math.min(meta.per_page, this.orders().length);

    return meta.total > shown ? `Showing ${shown} of ${meta.total.toLocaleString()} ${noun}` : `${meta.total.toLocaleString()} ${noun}`;
  });

  constructor() {
    // A different set of rows makes the ticks meaningless.
    effect(() => {
      this.list.criteria();
      untracked(() => this.selection.clear());
    });

    // Ticks on rows that are still there survive a reload.
    effect(() => {
      const ids = this.orders().map((order) => order.id);
      untracked(() => this.selection.keep(ids));
    });

    this.api.eventOptions().subscribe({
      next: ({ data }) => this.events.set(data),
      error: () => undefined,
    });
  }

  /** After a failure: the same question again. */
  load(): void {
    this.page.retry();
  }

  setRange(range: DateRange): void {
    this.list.set('from', range.from);
    this.list.set('to', range.to);
  }

  setAmounts(range: AmountRange): void {
    this.list.set('min_total', range.min);
    this.list.set('max_total', range.max);
  }

  remove(key: string): void {
    if (key === 'range') {
      this.setRange({ from: null, to: null });
    } else if (key === 'amount') {
      this.setAmounts({ min: null, max: null });
    } else {
      this.list.clear(key as 'q');
    }
  }

  sortBy(sort: Sort): void {
    this.list.sortBy(sort);
  }

  /** Every order the filter matches — or only the ticked ones — in the list's order. */
  export(onlySelected = false): void {
    if (this.exporting()) return;

    this.exporting.set(true);

    const query: ListQuery = onlySelected
      ? { ids: this.selection.ids(), sort: this.list.sort()?.column, dir: this.list.sort()?.direction }
      : withZone(this.list.criteria());

    this.api.exportOrders(query).subscribe({
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

  cash(money: Money): string {
    return formatMoney(money);
  }

  label(order: OrganizationOrder): string {
    return STATUSES.find((status) => status.value === order.status)?.label ?? 'Paid';
  }

  tone(order: OrganizationOrder): 'neutral' | 'success' | 'warning' | 'danger' {
    if (order.status === 'refunded') return 'danger';
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

/** A query with days in it names the reader's zone (see Orders.question). */
function withZone(query: ListQuery): ListQuery {
  return query['from'] || query['to'] ? { ...query, timezone: rangeZone() } : query;
}

/** "Afro Fest", "Afro Fest and Day Party", "Afro Fest and 2 more". */
function listed(names: readonly string[]): string {
  if (names.length <= 1) return names[0] ?? '';
  if (names.length === 2) return `${names[0]} and ${names[1]}`;
  return `${names[0]} and ${names.length - 1} more`;
}
