import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { EventDetail, Quote } from '../../core/api.types';
import { CheckoutStore } from '../../core/checkout-store';
import { formatMoney } from '../../core/money';
import { CheckoutSteps } from '../../shared/checkout-steps';

/**
 * Step two: who the tickets are for, and the itemized money.
 *
 * There is deliberately no card form on this page. Payment happens on the
 * processor's own page — Stripe for CAD, Paystack for NGN — so a card number
 * never touches this origin; what this page owes the buyer is the full bill
 * before they are sent there: subtotal, discount, service charge, tax, total,
 * every line priced by the server.
 */
@Component({
  selector: 'mf-checkout',
  standalone: true,
  imports: [FormsModule, RouterLink, CheckoutSteps],
  templateUrl: './checkout.html',
})
export class Checkout {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly store = inject(CheckoutStore);

  readonly event = signal<EventDetail | null>(null);
  readonly quote = signal<Quote | null>(null);
  readonly quoteError = signal<string | null>(null);
  readonly orderError = signal<string | null>(null);
  readonly placing = signal(false);

  readonly first = signal('');
  readonly last = signal('');
  readonly email = signal('');
  readonly confirm = signal('');
  readonly agreed = signal(false);

  readonly promo = signal('');

  readonly formatMoney = formatMoney;

  readonly slug = this.route.snapshot.paramMap.get('slug')!;

  readonly emailsDisagree = computed(
    () =>
      this.confirm().trim() !== '' &&
      this.email().trim().toLowerCase() !== this.confirm().trim().toLowerCase(),
  );

  readonly ready = computed(
    () =>
      this.first().trim() !== '' &&
      this.email().trim() !== '' &&
      !this.emailsDisagree() &&
      this.confirm().trim() !== '' &&
      this.agreed() &&
      this.quote() !== null,
  );

  constructor() {
    this.store.loadFor(this.slug);

    // Arriving with nothing chosen — a bookmark, an expired session — goes
    // back to the choosing, not to an empty bill.
    if (this.store.lines().length === 0) {
      void this.router.navigate(['/', this.slug, 'tickets']);
      return;
    }

    this.promo.set(this.store.code());

    this.api.event(this.slug).subscribe({
      next: ({ data }) => this.event.set(data),
      error: () => undefined,
    });

    this.refreshQuote();
  }

  applyCode(): void {
    this.store.setCode(this.slug, this.promo().trim());
    this.refreshQuote();
  }

  clearCode(): void {
    this.promo.set('');
    this.store.setCode(this.slug, '');
    this.refreshQuote();
  }

  placeOrder(): void {
    if (!this.ready() || this.placing()) return;

    this.placing.set(true);
    this.orderError.set(null);

    const name = `${this.first().trim()} ${this.last().trim()}`.trim();

    this.api
      .order(
        this.slug,
        this.store.lines(),
        { name, email: this.email().trim() },
        this.store.code() || undefined,
        this.store.ref() ?? undefined,
      )
      .subscribe({
        next: (order) => {
          this.store.clear(this.slug);

          if (order.payment) {
            // The processor's page, same tab. The order page picks the story
            // back up when they return.
            window.location.href = order.payment.redirect_url;
          } else {
            void this.router.navigate(['/order', order.reference]);
          }
        },
        error: (response) => {
          this.placing.set(false);
          this.orderError.set(
            response?.error?.message ??
              'That order could not be placed. Nothing has been charged — please try again.',
          );
        },
      });
  }

  private refreshQuote(): void {
    this.quoteError.set(null);

    this.api
      .quote(this.slug, this.store.lines(), this.store.code() || undefined, this.store.ref() ?? undefined)
      .subscribe({
        next: (quote) => this.quote.set(quote),
        error: (response) => {
          this.quote.set(null);
          this.quoteError.set(response?.error?.message ?? 'We could not price that basket.');
        },
      });
  }
}
