import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { OrderTicket, SoldOrder } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { formatMoney } from '../../core/money';
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
  imports: [FormsModule, RouterLink],
  templateUrl: './event-orders.html',
  styleUrl: './event-orders.css',
})
export class EventOrders {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = this.route.snapshot.paramMap.get('id')!;
  readonly money = formatMoney;

  readonly orders = signal<SoldOrder[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  /** Which order is being refunded, and which of its tickets are picked. */
  readonly openId = signal<string | null>(null);
  readonly picked = signal<Set<string>>(new Set());
  readonly reason = signal('');
  readonly working = signal(false);

  readonly search = signal('');

  readonly visible = computed(() => {
    const needle = this.search().trim().toLowerCase();

    if (!needle) return this.orders();

    return this.orders().filter(
      (order) =>
        order.reference.toLowerCase().includes(needle) ||
        order.buyer_name.toLowerCase().includes(needle) ||
        order.buyer_email.toLowerCase().includes(needle),
    );
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.orders(this.eventId).subscribe({
      next: ({ data }) => {
        this.orders.set(data);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(messageFor(response, 'Could not load orders for this event.'));
      },
    });
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

  submit(order: SoldOrder): void {
    if (this.working()) return;

    const refundable = order.tickets.filter((t) => t.refundable);
    const picked = [...this.picked()];

    if (picked.length === 0) {
      this.error.set('Pick at least one ticket to refund.');

      return;
    }

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
          this.load();
        },
        error: (response) => {
          this.working.set(false);
          this.error.set(messageFor(response, 'That refund could not be completed.'));
        },
      });
  }

  label(order: SoldOrder): string {
    if (order.status === 'refunded') return 'Refunded';
    if (order.status === 'partially_refunded') return 'Part refunded';

    return 'Paid';
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
