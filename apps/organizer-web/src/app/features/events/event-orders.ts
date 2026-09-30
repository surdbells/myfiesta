import { Component, computed, inject, signal } from '@angular/core';
import {
  ConfirmDialog,
  UiBadge,
  UiButton,
  UiColumnMenu,
  UiEmpty,
  UiErrorState,
  UiFilterBar,
  UiPagination,
  UiScrollRegion,
  UiSelect,
  UiSortHeader,
  UiTable,
  createListState,
  type FilterChip,
  type SelectOption,
} from '@myfiesta/ui';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { OrderTicket, PageMeta, SoldOrder } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { loadList, searchBox } from '../../core/list-loader';
import { formatMoney } from '../../core/money';
import { SavedViews } from '../../shared/saved-views';

const STATUSES: SelectOption[] = [
  { value: 'paid', label: 'Paid' },
  { value: 'partially_refunded', label: 'Part refunded' },
  { value: 'refunded', label: 'Refunded' },
];
import { SessionStore } from '../../core/session';

/**
 * What has been sold, and sending money back.
 *
 * Refunds are chosen by ticket, never typed as an amount. Refunding "£40"
 * leaves every ticket valid and the door with no idea, so the person who was
 * paid back still walks in — and nobody notices until reconciliation. Picking
 * the tickets makes the amount exact and turning them off part of the same act.
 *
 * The amount is therefore shown, not entered. The server works out what those
 * tickets are worth from what was actually charged, which is the same rule that
 * stops a buyer choosing what to pay.
 */
@Component({
  selector: 'app-event-orders',
  imports: [
    FormsModule,
    SavedViews,
    UiBadge,
    UiButton,
    UiColumnMenu,
    UiEmpty,
    UiErrorState,
    UiFilterBar,
    UiPagination,
    UiScrollRegion,
    UiSelect,
    UiSortHeader,
    UiTable,
  ],
  templateUrl: './event-orders.html',
})
export class EventOrders {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  readonly eventId = eventIdFrom(this.route);
  readonly money = formatMoney;

  readonly statusOptions = STATUSES;

  readonly list = createListState({
    list: 'event-orders',
    filters: {
      q: { kind: 'text' },
      status: { kind: 'many' },
    },
    sort: { column: 'paid_at', direction: 'desc' },
    columns: [
      { id: 'buyer', label: 'Buyer', required: true },
      { id: 'reference', label: 'Reference' },
      { id: 'tickets', label: 'Tickets' },
      { id: 'when', label: 'When' },
      { id: 'total', label: 'Total' },
      { id: 'status', label: 'Status' },
    ],
  });

  readonly search = searchBox(this.list, 'q');

  readonly page = loadList(this.list.query, () => this.api.orders(this.eventId, this.list.query()));

  readonly orders = computed<SoldOrder[]>(() => this.page.result()?.data ?? []);
  readonly meta = computed<PageMeta | null>(() => this.page.result()?.meta ?? null);
  readonly loading = computed(() => this.page.loading() && this.page.result() === null);
  readonly refreshing = computed(() => this.page.loading() && this.page.result() !== null);
  readonly failed = this.page.failed;
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  readonly openId = signal<string | null>(null);
  readonly picked = signal<Set<string>>(new Set());
  readonly reason = signal('');
  readonly working = signal(false);

  readonly canRefund = computed(() => this.session.canSeeMoney() || this.session.canEditEvents());

  readonly filtered = computed(() => this.list.active() > 0);

  readonly chips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const values = this.list.values();

    if (values.q) chips.push({ key: 'q', label: 'Search', value: String(values.q) });

    const statuses = values.status as readonly string[];
    if (statuses.length > 0) {
      chips.push({
        key: 'status',
        label: 'Status',
        value: statuses.map((s) => STATUSES.find((o) => o.value === s)?.label ?? s).join(' or '),
      });
    }

    return chips;
  });

  readonly summary = computed(() => {
    const meta = this.meta();
    if (!meta) return null;

    const noun = meta.total === 1 ? 'order' : 'orders';
    return `${meta.total.toLocaleString()} ${noun}`;
  });

  /** After a failure: the same question again. */
  load(): void {
    this.page.retry();
  }

  remove(key: string): void {
    this.list.clear(key as 'q');
  }

  open(order: SoldOrder): void {
    this.error.set(null);
    this.notice.set(null);
    this.reason.set('');

    if (this.openId() === order.id) {
      this.openId.set(null);

      return;
    }

    this.openId.set(order.id);
    // Everything still refundable, preselected. The common case is the whole
    // order, and unticking two is less work than ticking eight.
    this.picked.set(new Set(order.tickets.filter((t) => t.refundable).map((t) => t.id)));
  }

  toggle(ticket: OrderTicket): void {
    const next = new Set(this.picked());

    next.has(ticket.id) ? next.delete(ticket.id) : next.add(ticket.id);
    this.picked.set(next);
  }

  isPicked(ticket: OrderTicket): boolean {
    return this.picked().has(ticket.id);
  }

  /**
   * What this selection is worth, as an estimate.
   *
   * Deliberately labelled as one. The server does the real arithmetic against
   * what was charged, and showing a figure here as if it were final would make
   * a rounding difference look like a bug.
   */
  estimate(order: SoldOrder): string {
    const refundable = order.tickets.filter((t) => t.refundable);
    const picked = refundable.filter((t) => this.isPicked(t));

    if (picked.length === 0) return this.money({ amount: 0, currency: order.currency });

    if (picked.length === refundable.length) return this.money(order.refundable);

    return this.money({
      amount: Math.round((order.refundable.amount * picked.length) / refundable.length),
      currency: order.currency,
    });
  }

  async submit(order: SoldOrder): Promise<void> {
    if (this.working()) return;

    const refundable = order.tickets.filter((t) => t.refundable);
    const picked = [...this.picked()];

    if (picked.length === 0) {
      this.error.set('Pick at least one ticket to refund.');

      return;
    }

    // Money leaving and tickets dying, both for good: the amount, who gets it
    // and what stops working are named before anything is sent.
    const tickets = `${picked.length} ${picked.length === 1 ? 'ticket' : 'tickets'}`;
    const whole = picked.length === refundable.length;
    const amount = whole ? this.money(order.refundable) : `about ${this.estimate(order)}`;
    const to = order.buyer_email ?? order.buyer_name;

    const sure = await this.confirmDialog.confirm({
      title: `Refund ${tickets} on ${order.reference}?`,
      body: `${amount} goes back to ${to}, the way they paid.`,
      consequences: [
        `The ${picked.length === 1 ? 'ticket stops' : 'tickets stop'} working at the door straight away.`,
        'A refund cannot be undone.',
      ],
      confirmLabel: `Refund ${tickets}`,
      tone: 'danger',
    });

    if (!sure || this.working()) return;

    this.working.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api
      .refund(this.eventId, order.id, {
        // Omitted entirely when it is everything, so the server refunds the
        // order rather than a list that happens to match it.
        ticket_ids: picked.length === refundable.length ? undefined : picked,
        reason: this.reason().trim() || null,
      })
      .subscribe({
        next: (refund) => {
          this.working.set(false);
          this.openId.set(null);
          this.notice.set(`${this.money(refund.amount)} sent back to ${order.buyer_name}.`);
          this.page.retry();
        },
        error: (response) => {
          this.working.set(false);
          this.error.set(messageFor(response, 'That refund could not be completed.'));
        },
      });
  }

  label(order: SoldOrder): string {
    return STATUSES.find((status) => status.value === order.status)?.label ?? 'Paid';
  }

  tone(order: SoldOrder): 'success' | 'warning' | 'danger' {
    if (order.status === 'refunded') return 'danger';
    if (order.status === 'partially_refunded') return 'warning';

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
