import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { EventDetail, Quote, TicketType } from '../../core/api.types';
import { CheckoutStore } from '../../core/checkout-store';
import { formatMoney } from '../../core/money';
import { Seo } from '../../core/seo';
import { CheckoutSteps } from '../../shared/checkout-steps';

/**
 * Step one: which tickets, and how many.
 *
 * Its own page rather than a rail on the event page, so the decision gets
 * the width it needs — a tier is a card with room for what it includes, not
 * a row squeezed beside a stepper. Quantities are the only thing chosen
 * here; every figure in the bar below is the server's.
 */
@Component({
  selector: 'mf-ticket-select',
  standalone: true,
  imports: [RouterLink, CheckoutSteps],
  templateUrl: './ticket-select.html',
})
export class TicketSelect {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);
  readonly store = inject(CheckoutStore);

  readonly event = signal<EventDetail | null>(null);
  readonly notFound = signal(false);
  readonly quote = signal<Quote | null>(null);

  readonly formatMoney = formatMoney;

  readonly slug = this.route.snapshot.paramMap.get('slug')!;

  readonly hasSelection = computed(() => this.store.count() > 0);

  /** On-sale first, then sold out — visible but plainly done. Hidden stays hidden. */
  readonly tiers = computed(() => {
    const types = this.event()?.ticket_types ?? [];
    const shown = types.filter((t) => t.status !== 'hidden');

    return [...shown].sort(
      (a, b) => Number(a.status !== 'on_sale') - Number(b.status !== 'on_sale'),
    );
  });

  constructor() {
    this.store.loadFor(this.slug);

    this.api.event(this.slug).subscribe({
      next: ({ data }) => {
        this.event.set(data);
        this.seo.forEvent(data, `https://myfiesta.ca/${data.slug}`);
        this.refreshQuote();
      },
      error: () => this.notFound.set(true),
    });
  }

  quantity(id: string): number {
    return this.store.items()[id] ?? 0;
  }

  adjust(type: TicketType, delta: number): void {
    if (type.status !== 'on_sale') return;

    const ceiling = type.max_per_order ?? 20;
    const next = Math.min(Math.max(this.quantity(type.id) + delta, 0), ceiling);

    this.store.setQuantity(this.slug, type.id, next);
    this.refreshQuote();
  }

  stepLabel(type: TicketType, direction: 'more' | 'fewer'): string {
    return `One ${direction} ${type.name}`;
  }

  continueToCheckout(): void {
    if (!this.hasSelection()) return;
    void this.router.navigate(['/', this.slug, 'checkout']);
  }

  private refreshQuote(): void {
    const lines = this.store.lines();

    if (lines.length === 0) {
      this.quote.set(null);
      return;
    }

    this.api.quote(this.slug, lines).subscribe({
      next: (quote) => this.quote.set(quote),
      error: () => this.quote.set(null),
    });
  }
}
