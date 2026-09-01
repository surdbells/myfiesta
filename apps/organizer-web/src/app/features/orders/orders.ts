import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  UiBadge,
  UiEmpty,
  UiErrorState,
  UiIcon,
  UiPageHeader,
  UiPagination,
  UiSkeleton,
} from '@myfiesta/ui';
import { Search } from 'lucide-angular';
import { Subject, debounceTime, distinctUntilChanged, map, switchMap } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api } from '../../core/api';
import { Money, OrganizationOrder, OrganizerEvent } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { SessionStore } from '../../core/session';

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
    UiEmpty,
    UiErrorState,
    UiIcon,
    UiPagination,
    UiSkeleton,
  ],
  templateUrl: './orders.html',
})
export class Orders {
  protected readonly searchIcon = Search;

  private readonly api = inject(Api);
  readonly session = inject(SessionStore);

  readonly orders = signal<OrganizationOrder[]>([]);
  readonly meta = signal<{ total: number; per_page: number; current_page: number } | null>(null);
  readonly summary = signal<{ gross: Money; refunded: Money; net: Money } | null>(null);

  readonly loading = signal(true);
  readonly failed = signal(false);
  /** A 403 is not a failure to load; it is an answer. */
  readonly refused = signal(false);

  readonly query = signal('');
  readonly eventId = signal('');
  readonly status = signal('');
  readonly page = signal(1);

  /** The filter's options, so somebody can narrow to one night. */
  readonly events = signal<OrganizerEvent[]>([]);

  readonly filtered = computed(() => !!this.query() || !!this.eventId() || !!this.status());

  private readonly requests = new Subject<void>();

  constructor() {
    // Typing runs a search, but not on every keystroke — and never twice for
    // the same terms, which is what a backspace-and-retype produces.
    this.requests
      .pipe(
        debounceTime(250),
        // Serialised on the query, not the void: two searches in flight can
        // land out of order and leave the slower one's rows on screen.
        map(() => JSON.stringify([this.query(), this.eventId(), this.status(), this.page()])),
        distinctUntilChanged(),
        switchMap(() =>
          this.api.organizationOrders({
            q: this.query(),
            event_id: this.eventId(),
            status: this.status(),
            page: this.page(),
          }),
        ),
        takeUntilDestroyed(),
      )
      .subscribe({
        next: (result) => {
          this.orders.set(result.data);
          this.meta.set(result.meta);
          this.summary.set(result.meta.summary);
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

    this.api.events().subscribe({
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

  clear(): void {
    this.query.set('');
    this.eventId.set('');
    this.status.set('');
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
