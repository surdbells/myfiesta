import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import type { Money, OrganizerEventDetail, PageMeta, SoldOrder } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { formatMoney } from '../../core/money';
import { messageOf } from '../../core/errors';
import { ago } from '../../core/when';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfCheck,
  MfEmpty,
  MfField,
  MfScreen,
  MfSearch,
  MfSheet,
  MfSkeleton,
  ToastStore,
} from '../../ui';
import { EventContext } from './event-context';

/**
 * What refunding some of an order's tickets comes to.
 *
 * Exact when it is all of them — the server refunds what is left on the
 * order. Otherwise an even share, and marked as not exact: the server weighs
 * each ticket by what was paid for it, so a table and a general admission on
 * the same order are not worth the same.
 */
export function refundEstimate(order: SoldOrder, picked: number): { amount: Money; exact: boolean } {
  const refundable = order.tickets.filter((t) => t.refundable).length;

  if (picked <= 0 || refundable === 0) return { amount: { amount: 0, currency: order.currency }, exact: true };
  if (picked >= refundable) return { amount: order.refundable, exact: true };

  return { amount: { amount: Math.round((order.refundable.amount * picked) / refundable), currency: order.currency }, exact: false };
}

/**
 * Who bought what, and giving it back.
 *
 * Found by reference, name or email — how support actually arrives. A refund
 * is chosen ticket by ticket, because the common case is not "refund the
 * order" but "one of the four cannot come"; picking all of them refunds the
 * order as a whole, which is also what the server is told.
 *
 * The amount is said before the button is pressed, and the button says what
 * it does. Money leaving is the one thing on this screen that cannot be
 * walked back.
 */
@Component({
  selector: 'mf-event-orders',
  imports: [MfScreen, MfSearch, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfCheck, MfField],
  template: `
    <mf-screen title="Orders" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="reload()">
      <mf-search screenBar placeholder="Reference, name or email" [(value)]="query" (searched)="reload()" />

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
        <mf-empty [title]="query() ? 'Nothing matches' : 'No orders yet'" [hint]="query() ? 'Try the reference from their email, or part of their name.' : null" />
      } @else {
        @if (meta(); as m) {
          <p class="count">{{ m.total }} {{ m.total === 1 ? 'order' : 'orders' }}</p>
        }
        <ul class="list">
          @for (order of orders(); track order.id) {
            <li>
              <mf-card tappable (click)="open.set(order)">
                <div class="top">
                  <span class="who">
                    <span class="name">{{ order.buyer_name }}</span>
                    <span class="sub">{{ order.reference }} · {{ since(order.paid_at) }}</span>
                  </span>
                  <span class="total">{{ cash(order.total) }}</span>
                </div>
                <div class="meta">
                  <mf-badge [tone]="tone(order)">{{ label(order) }}</mf-badge>
                  <span>{{ order.tickets.length }} {{ order.tickets.length === 1 ? 'ticket' : 'tickets' }}</span>
                  @if (order.refunded.amount > 0) {
                    <span>{{ cash(order.refunded) }} back</span>
                  }
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

    <mf-sheet [open]="!!open()" [heading]="open()?.buyer_name ?? 'Order'" [subheading]="open() ? open()!.reference + (open()!.buyer_email ? ' · ' + open()!.buyer_email : '') : null" closable (closed)="close()">
      @if (open(); as order) {
        <dl class="facts">
          <div><dt>Paid</dt><dd>{{ cash(order.total) }}</dd></div>
          <div><dt>Refunded</dt><dd>{{ cash(order.refunded) }}</dd></div>
          <div><dt>Refundable</dt><dd>{{ cash(order.refundable) }}</dd></div>
        </dl>

        <p class="group-label">Tickets</p>
        <div class="tickets">
          @for (ticket of order.tickets; track ticket.id) {
            @if (ticket.refundable && canRefund()) {
              <mf-check
                [label]="(ticket.holder_name || 'Unnamed') + ' — ' + (ticket.ticket_type_name ?? 'Ticket')"
                [hint]="ticket.status === 'checked_in' ? 'Already through the door' : null"
                [value]="picked().includes(ticket.id)"
                (valueChange)="toggle(ticket.id, $event)"
              />
            } @else {
              <p class="ticket-row">
                <span>{{ ticket.holder_name || 'Unnamed' }} — {{ ticket.ticket_type_name ?? 'Ticket' }}</span>
                <mf-badge>{{ ticket.status === 'refunded' ? 'Refunded' : ticket.status === 'checked_in' ? 'In' : 'Kept' }}</mf-badge>
              </p>
            }
          }
        </div>

        @if (picked().length > 0) {
          <p class="estimate">
            {{ refundExact() ? '' : 'About ' }}<strong>{{ refundAmount() }}</strong> goes back to {{ order.buyer_name }}.
          </p>
        }

        @if (canRefund() && refundable(order).length > 0) {
          <mf-field class="reason" label="Reason" optional hint="The buyer reads this in the email that tells them.">
            <input [value]="reason()" (input)="reason.set($any($event.target).value)" placeholder="Could not make it" />
          </mf-field>
        }
      }

      @if (open() && canRefund() && refundable(open()!).length > 0) {
        <ng-container sheetFooter>
          <button mfButton variant="secondary" (click)="pickAll(open()!)">{{ picked().length === refundable(open()!).length ? 'Pick none' : 'Pick all' }}</button>
          <button mfButton variant="danger" [loading]="working()" [disabled]="picked().length === 0" (click)="refund(open()!)">
            Refund
          </button>
        </ng-container>
      }
    </mf-sheet>
  `,
  styles: `
    .count {
      margin: 0 var(--space-1) var(--space-3);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
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
    }

    .total {
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }

    .meta {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .more {
      margin-top: var(--space-4);
    }

    .facts {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--space-3);
      margin: 0 0 var(--space-5);
      padding: var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--surface-inset);
    }

    .facts div {
      display: grid;
      gap: 2px;
    }

    dt {
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    dd {
      margin: 0;
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }

    .group-label {
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
    }

    .tickets {
      display: grid;
    }

    .ticket-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-3);
      min-height: var(--mf-tap);
      color: var(--text-muted);
    }

    .estimate {
      margin-top: var(--space-3);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--warning) 12%, transparent);
      font-size: var(--font-size-sm);
      font-variant-numeric: tabular-nums;
    }

    .reason {
      display: block;
      margin-top: var(--space-4);
    }
  `,
})
export class EventOrders implements OnInit {
  readonly id = input.required<string>();
  /** A reference to find straight away, from the organization's order list. */
  readonly q = input<string | undefined>(undefined);

  private readonly organizer = inject(Organizer);
  private readonly session = inject(SessionStore);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly orders = signal<SoldOrder[]>([]);
  protected readonly meta = signal<PageMeta | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly query = signal('');

  protected readonly open = signal<SoldOrder | null>(null);
  protected readonly picked = signal<string[]>([]);
  protected readonly reason = signal('');
  protected readonly working = signal(false);

  protected readonly cash = formatMoney;
  protected readonly since = ago;
  protected readonly canRefund = computed(() => this.session.can('refunds.process'));

  private readonly estimate = computed(() => {
    const order = this.open();
    return order ? refundEstimate(order, this.picked().length) : null;
  });

  protected readonly refundAmount = computed(() => {
    const e = this.estimate();
    return e ? formatMoney(e.amount) : '';
  });

  protected readonly refundExact = computed(() => this.estimate()?.exact ?? false);

  protected hasMore(): boolean {
    const m = this.meta();
    return !!m && m.current_page < m.last_page;
  }

  ngOnInit(): void {
    if (this.q()) this.query.set(this.q()!);
    this.event.set(this.context.peek(this.id()));
    void this.context.get(this.id()).then((e) => this.event.set(e)).catch(() => undefined);
    void this.reload();
  }

  async reload(): Promise<void> {
    this.orders.set([]);
    await this.fetch(1);
  }

  protected more(): void {
    void this.fetch((this.meta()?.current_page ?? 1) + 1);
  }

  private async fetch(page: number): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const result = await this.organizer.eventOrders(this.id(), page, this.query());
      this.orders.update((rows) => (page === 1 ? result.data : [...rows, ...result.data]));
      this.meta.set(result.meta);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected tone(order: SoldOrder): 'success' | 'warning' | 'neutral' {
    return order.status === 'paid' ? 'success' : order.status === 'partially_refunded' ? 'warning' : 'neutral';
  }

  protected label(order: SoldOrder): string {
    return order.status === 'refunded' ? 'Refunded' : order.status === 'partially_refunded' ? 'Part refunded' : 'Paid';
  }

  protected refundable(order: SoldOrder) {
    return order.tickets.filter((t) => t.refundable);
  }

  protected toggle(ticketId: string, on: boolean): void {
    this.picked.update((ids) => (on ? [...ids, ticketId] : ids.filter((id) => id !== ticketId)));
  }

  protected pickAll(order: SoldOrder): void {
    const all = this.refundable(order).map((t) => t.id);
    this.picked.set(this.picked().length === all.length ? [] : all);
  }

  protected close(): void {
    this.open.set(null);
    this.picked.set([]);
    this.reason.set('');
  }

  protected async refund(order: SoldOrder): Promise<void> {
    const refundable = this.refundable(order);
    const picked = this.picked();
    const amount = (this.refundExact() ? '' : 'about ') + this.refundAmount();

    const tickets = `${picked.length} ${picked.length === 1 ? 'ticket' : 'tickets'}`;
    const sure = await this.dialogs.confirm({
      title: `Refund ${tickets} on ${order.reference}?`,
      body: `${amount} goes back to ${order.buyer_email ?? order.buyer_name}, the way they paid.`,
      consequences: [`The ${picked.length === 1 ? 'ticket stops' : 'tickets stop'} working at the door straight away.`, 'A refund cannot be undone.'],
      confirmLabel: `Refund ${tickets}`,
      tone: 'danger',
    });

    if (!sure || this.working()) return;

    this.working.set(true);

    try {
      const result = await this.organizer.refund(this.id(), order.id, {
        // Omitted when it is everything, so the server refunds the order
        // rather than a list that happens to match it.
        ticket_ids: picked.length === refundable.length ? undefined : picked,
        reason: this.reason().trim() || null,
      });

      this.close();
      this.toasts.show(`${formatMoney(result.amount)} sent back to ${order.buyer_name}.`, 'success');
      await this.reload();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That refund could not be completed.'), 'danger');
    } finally {
      this.working.set(false);
    }
  }
}
