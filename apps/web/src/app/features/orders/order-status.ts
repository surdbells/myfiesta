import { CommonModule } from '@angular/common';
import { Component, OnDestroy, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Api } from '../../core/api';
import { OrderStatus as OrderStatusModel } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { AddToCalendar } from '../../shared/add-to-calendar';

/**
 * Where a buyer lands after paying.
 *
 * The return from a gateway is a navigation event, not proof of anything — a
 * signed webhook decides whether an order is paid. So this page asks the server
 * and keeps asking, rather than announcing success because a redirect happened.
 *
 * The wait is usually a second or two. It is presented as a wait rather than an
 * outcome, because telling someone their payment failed when it is merely still
 * arriving is worse than making them look at a spinner.
 */
@Component({
  selector: 'mf-order-status',
  standalone: true,
  imports: [CommonModule, AddToCalendar],
  templateUrl: './order-status.html',
})
export class OrderStatus implements OnDestroy {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);

  readonly order = signal<OrderStatusModel | null>(null);
  readonly notFound = signal(false);
  readonly stillWaiting = signal(false);

  readonly formatMoney = formatMoney;

  private timer: ReturnType<typeof setTimeout> | null = null;
  private attempts = 0;

  /**
   * Give up asking after roughly a minute.
   *
   * Not because the payment failed — it may still land, and the webhook will
   * still fulfil it — but because a page that polls forever is a page nobody
   * closes. The tickets arrive by email regardless.
   */
  private static readonly MAX_ATTEMPTS = 20;

  constructor() {
    this.poll(this.route.snapshot.paramMap.get('reference')!);
  }

  private poll(reference: string): void {
    this.api.orderStatus(reference).subscribe({
      next: (order) => {
        this.order.set(order);

        if (order.status === 'pending' && this.attempts < OrderStatus.MAX_ATTEMPTS) {
          this.attempts++;
          this.stillWaiting.set(this.attempts > 2);
          this.timer = setTimeout(() => this.poll(reference), 3000);
        }
      },
      error: () => this.notFound.set(true),
    });
  }

  ngOnDestroy(): void {
    if (this.timer) clearTimeout(this.timer);
  }

  when(): string {
    const order = this.order();
    if (!order) return '';

    return new Intl.DateTimeFormat('en-CA', {
      dateStyle: 'full',
      timeStyle: 'short',
      timeZone: order.event.timezone,
    }).format(new Date(order.event.starts_at));
  }
}
