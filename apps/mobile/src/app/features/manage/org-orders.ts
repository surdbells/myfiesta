import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Share2 } from 'lucide-angular';
import type { EventOption, OrderSignal, OrganizationOrder, OrganizationOrderPage } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { ago } from '../../core/when';
import { shareFile } from '../../core/share-file';
import {
  MfBadge,
  MfButton,
  MfCard,
  MfChips,
  MfEmpty,
  MfIconButton,
  MfScreen,
  MfSearch,
  MfSelect,
  MfSkeleton,
  ToastStore,
  type MfChip,
  type MfOption,
} from '../../ui';

const SIGNALS: Record<OrderSignal, string> = {
  disputed: 'Charged back',
  previous_chargeback: 'Chargeback before',
  many_orders: 'Several orders today',
};

type Range = '' | 'today' | 'week' | 'month';

/**
 * Every order the organization has taken, across every night.
 *
 * The question support arrives with is a reference or an address, not a
 * night — so this searches all of them. What matches is said as a count and,
 * where the page is in one currency, as money; a filtered list that reads
 * like an unfiltered one is how a week's takings get mistaken for the year's.
 *
 * Refunds are made from the order's own event, where the tickets are; tapping
 * an order goes there with it already found.
 */
@Component({
  selector: 'mf-org-orders',
  imports: [MfScreen, MfSearch, MfChips, MfSelect, MfIconButton, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton],
  template: `
    <mf-screen title="Orders" back backTo="/manage" refreshable [busy]="loading()" (refresh)="reload()">
      @if (session.can('money.view')) {
        <button mfIconButton screenActions tone="tonal" [icon]="shareIcon" label="Share these orders as a spreadsheet" [disabled]="exporting()" (click)="export()"></button>
      }
      <mf-search screenBar placeholder="Reference, name or email" [(value)]="query" (searched)="reload()" />

      <div class="filters">
        <mf-chips ariaLabel="When" [options]="ranges" [value]="range()" (valueChange)="range.set($any($event)); reload()" />
        <div class="pair">
          <mf-select heading="Event" placeholder="All events" [options]="eventChoices()" [value]="eventId()" (valueChange)="eventId.set($event ?? ''); reload()" />
          <mf-select heading="Status" placeholder="Any status" [options]="statuses" [value]="status()" (valueChange)="status.set($event ?? ''); reload()" />
        </div>
      </div>

      @if (page(); as p) {
        <p class="count">
          <strong>{{ p.meta.total.toLocaleString() }}</strong> {{ p.meta.total === 1 ? 'order' : 'orders' }}{{ filtered() ? ' match' : '' }}
          @if (p.meta.summary; as s) {
            · {{ cash(s.net) }} after refunds
          }
        </p>
      }

      @if (error(); as message) {
        <mf-empty title="Could not load the orders" [hint]="message">
          <button mfButton variant="secondary" (click)="reload()">Try again</button>
        </mf-empty>
      } @else if (orders().length === 0 && loading()) {
        <div class="list">
          @for (n of [0, 1, 2]; track n) {
            <mf-card quiet><mf-skeleton height="3rem" /></mf-card>
          }
        </div>
      } @else if (orders().length === 0) {
        <mf-empty [title]="filtered() ? 'Nothing matches' : 'No orders yet'" [hint]="filtered() ? 'Try fewer filters, or part of their name.' : null" />
      } @else {
        <ul class="list">
          @for (order of orders(); track order.id) {
            <li>
              <mf-card tappable (click)="open(order)">
                <div class="top">
                  <span class="who">
                    <span class="name">{{ order.buyer_name }}</span>
                    <span class="sub">{{ order.event?.title ?? 'Removed event' }}</span>
                  </span>
                  <span class="total">{{ cash(order.total) }}</span>
                </div>
                <div class="meta">
                  <mf-badge [tone]="tone(order)">{{ label(order) }}</mf-badge>
                  @for (s of order.signals; track s) {
                    <mf-badge tone="danger">{{ signal(s) }}</mf-badge>
                  }
                  <span>{{ order.reference }} · {{ since(order.paid_at) }}</span>
                </div>
              </mf-card>
            </li>
          }
        </ul>

        @if (hasMore()) {
          <button mfButton class="more" variant="secondary" block [loading]="loading()" (click)="more()">Show more</button>
        }
      }
    </mf-screen>
  `,
  styles: `
    .filters {
      display: grid;
      gap: var(--space-3);
      margin-bottom: var(--space-4);
    }

    .pair {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-2);
    }

    .count {
      margin: 0 var(--space-1) var(--space-3);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .count strong {
      color: var(--text);
    }

    .list {
      display: grid;
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .top {
      display: flex;
      align-items: baseline;
      gap: var(--space-3);
    }

    .who {
      flex: 1;
      display: grid;
      gap: 1px;
      min-width: 0;
    }

    .name {
      font-weight: var(--font-weight-semibold);
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .total {
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }

    .meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2);
      margin-top: var(--space-2);
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    .more {
      margin-top: var(--space-4);
    }
  `,
})
export class OrgOrders implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly router = inject(Router);
  private readonly toasts = inject(ToastStore);
  protected readonly session = inject(SessionStore);

  protected readonly query = signal('');
  protected readonly range = signal<Range>('');
  protected readonly eventId = signal('');
  protected readonly status = signal('');

  protected readonly page = signal<OrganizationOrderPage | null>(null);
  protected readonly orders = signal<OrganizationOrder[]>([]);
  protected readonly events = signal<EventOption[]>([]);
  protected readonly loading = signal(true);
  protected readonly exporting = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly shareIcon = Share2;
  protected readonly cash = formatMoney;
  protected readonly since = ago;

  protected readonly ranges: MfChip[] = [
    { value: '', label: 'Any time' },
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'Last 7 days' },
    { value: 'month', label: 'Last 30 days' },
  ];

  protected readonly statuses: MfOption[] = [
    { value: '', label: 'Any status' },
    { value: 'paid', label: 'Paid' },
    { value: 'partially_refunded', label: 'Part refunded' },
    { value: 'refunded', label: 'Refunded' },
    { value: 'pending', label: 'Confirming' },
  ];

  protected readonly eventChoices = computed<MfOption[]>(() => [
    { value: '', label: 'All events' },
    ...this.events().map((e) => ({ value: e.id, label: e.title, hint: new Date(e.starts_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) })),
  ]);

  protected readonly filtered = computed(() => !!(this.query() || this.range() || this.eventId() || this.status()));

  ngOnInit(): void {
    void this.organizer.eventOptions().then((e) => this.events.set(e)).catch(() => undefined);
    void this.reload();
  }

  async reload(): Promise<void> {
    this.orders.set([]);
    await this.fetch(1);
  }

  protected hasMore(): boolean {
    const m = this.page()?.meta;
    return !!m && m.current_page < m.last_page;
  }

  protected more(): void {
    void this.fetch((this.page()?.meta.current_page ?? 1) + 1);
  }

  /** The filters as the API reads them; dates are the phone's own days. */
  private filters() {
    const day = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const back = (days: number) => day(new Date(Date.now() - days * 86_400_000));
    const range = this.range();

    return {
      q: this.query().trim() || undefined,
      event_id: this.eventId() || undefined,
      status: this.status() || undefined,
      from: range === 'today' ? back(0) : range === 'week' ? back(6) : range === 'month' ? back(29) : undefined,
    };
  }

  private async fetch(page: number): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const result = await this.organizer.orders({ ...this.filters(), page });
      this.page.set(result);
      this.orders.update((rows) => (page === 1 ? result.data : [...rows, ...result.data]));
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected tone(order: OrganizationOrder): 'success' | 'warning' | 'neutral' {
    return order.status === 'paid' ? 'success' : order.status === 'refunded' ? 'neutral' : 'warning';
  }

  protected label(order: OrganizationOrder): string {
    return { paid: 'Paid', partially_refunded: 'Part refunded', refunded: 'Refunded', pending: 'Confirming' }[order.status];
  }

  protected signal(s: OrderSignal): string {
    return SIGNALS[s] ?? s;
  }

  protected open(order: OrganizationOrder): void {
    if (!order.event) return;
    void this.router.navigate(['/manage/events', order.event.id, 'orders'], { queryParams: { q: order.reference } });
  }

  protected async export(): Promise<void> {
    this.exporting.set(true);

    try {
      const csv = await this.organizer.exportOrders(this.filters());
      await shareFile(csv, `orders-${new Date().toISOString().slice(0, 10)}.csv`, 'Orders');
    } catch (error) {
      this.toasts.show(messageOf(error, 'The orders could not be exported.'), 'danger');
    } finally {
      this.exporting.set(false);
    }
  }
}
