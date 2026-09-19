import { CommonModule } from '@angular/common';
import { Component, OnDestroy, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Api } from '../../core/api';
import { EmbedMode, paymentFor } from '../../core/embed';
import { Seo } from '../../core/seo';
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
  private readonly seo = inject(Seo);
  readonly embed = inject(EmbedMode);

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

  /**
   * Inside a frame, the buyer is paying in another tab and may take a while
   * over it — finding a card, a bank's own check. Ten minutes, then the same
   * honest "it will arrive by email".
   */
  private static readonly MAX_ATTEMPTS_FRAMED = 200;

  /** Where to pay, if the tab we opened never did. */
  payment: string | null = null;

  constructor() {
    this.seo.forPrivatePage('Your order');
    const reference = this.route.snapshot.paramMap.get('reference')!;
    this.payment = this.embed.active() ? paymentFor(reference) : null;
    this.poll(reference);
  }

  private poll(reference: string): void {
    this.api.orderStatus(reference).subscribe({
      next: (order) => {
        this.order.set(order);

        // The page around the frame may want to say thank you, or count a
        // sale. What it is told is what the buyer can see anyway.
        if (order.status === 'paid') {
          this.embed.tell({ type: 'paid', event: order.event.slug, tickets: order.ticket_count });
        }

        const limit = this.embed.active() ? OrderStatus.MAX_ATTEMPTS_FRAMED : OrderStatus.MAX_ATTEMPTS;

        if (order.status === 'pending' && this.attempts < limit) {
          this.attempts++;
          this.stillWaiting.set(this.attempts > (this.embed.active() ? 40 : 2));
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
