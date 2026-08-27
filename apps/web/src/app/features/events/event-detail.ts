import { CommonModule } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Api } from '../../core/api';
import { EventDetail as EventDetailModel, Quote, TicketType } from '../../core/api.types';
import { formatMoney } from '../../core/money';
import { Seo } from '../../core/seo';

/**
 * The page a shared link lands on, and where a sale happens.
 *
 * Quantities are the only thing this component ever sends. Every figure with a
 * currency in front of it comes back from the server — the previous platform
 * posted its own total to the payment gateway, so buyers set their own price.
 */
@Component({
  selector: 'mf-event-detail',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './event-detail.html',
})
export class EventDetail {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);

  readonly event = signal<EventDetailModel | null>(null);
  readonly notFound = signal(false);
  readonly quote = signal<Quote | null>(null);
  readonly quoteError = signal<string | null>(null);

  /** Opens once a basket exists, so the form is not in the way while browsing. */
  readonly checkingOut = signal(false);
  readonly placing = signal(false);
  readonly orderError = signal<string | null>(null);

  readonly buyer = { name: '', email: '', phone: '' };

  /** A code the buyer typed, as opposed to the ref carried by a shared link. */
  readonly code = signal('');

  /** ticket type id -> quantity */
  readonly basket = signal<Record<string, number>>({});

  /** Captured from the link and carried to the order, so a promoter gets credit. */
  private ref: string | null = null;

  readonly formatMoney = formatMoney;

  readonly hasSelection = computed(() => Object.values(this.basket()).some((q) => q > 0));

  constructor() {
    this.ref = this.route.snapshot.queryParamMap.get('ref');

    const slug = this.route.snapshot.paramMap.get('slug')!;

    this.api.event(slug).subscribe({
      next: ({ data }) => {
        this.event.set(data);
        this.seo.forEvent(data, `https://myfiesta.ca/${data.slug}`);
      },
      error: () => this.notFound.set(true),
    });
  }

  onSale(): TicketType[] {
    return this.event()?.ticket_types.filter((t) => t.status === 'on_sale') ?? [];
  }

  quantity(id: string): number {
    return this.basket()[id] ?? 0;
  }

  adjust(type: TicketType, delta: number): void {
    const current = this.quantity(type.id);
    const ceiling = type.max_per_order ?? 20;
    const next = Math.min(Math.max(current + delta, 0), ceiling);

    if (next === current) return;

    this.basket.update((b) => ({ ...b, [type.id]: next }));
    this.reprice();
  }

  /**
   * Re-price on every change.
   *
   * Free to call: quoting takes no locks and writes nothing, which is why it is
   * a separate endpoint from placing an order.
   */
  private reprice(): void {
    const items = this.items();

    if (items.length === 0) {
      this.quote.set(null);
      this.quoteError.set(null);
      this.checkingOut.set(false);
      return;
    }

    const typed = this.code().trim() || undefined;

    this.api.quote(this.event()!.slug, items, typed, this.ref ?? undefined).subscribe({
      next: (quote) => {
        this.quote.set(quote);
        this.quoteError.set(null);
      },
      error: (response) => {
        this.quote.set(null);
        // The server writes these for a person at a checkout, so they are shown
        // as-is rather than replaced with something generic.
        this.quoteError.set(response?.error?.message ?? 'That basket cannot be priced.');
      },
    });
  }

  /**
   * Which code the server actually accepted.
   *
   * Read back from the quote rather than echoed from the input, so a code that
   * was typed but rejected never appears applied. The server is the only thing
   * that knows whether it counted.
   */
  readonly appliedCode = computed(() => this.quote()?.code_applied ?? null);

  clearCode(): void {
    this.code.set('');
    this.reprice();
  }

  private items(): { ticket_type_id: string; quantity: number }[] {
    return Object.entries(this.basket())
      .filter(([, quantity]) => quantity > 0)
      .map(([ticket_type_id, quantity]) => ({ ticket_type_id, quantity }));
  }

  when(): string {
    const event = this.event();
    if (!event) return '';

    return new Intl.DateTimeFormat('en-CA', {
      dateStyle: 'full',
      timeStyle: 'short',
      timeZone: event.timezone,
    }).format(new Date(event.starts_at));
  }

  stepLabel(type: TicketType, direction: 'more' | 'fewer'): string {
    return direction === 'more' ? `One more ${type.name}` : `One fewer ${type.name}`;
  }

  applyCode(): void {
    if (!this.code().trim()) return;

    // Goes through the same path as a quantity change. The discount, the tax
    // on the discounted amount and the total all come back from the server
    // together — nothing about a price is adjusted in the browser.
    this.reprice();
  }

  beginCheckout(): void {
    this.orderError.set(null);
    this.checkingOut.set(true);
  }

  /**
   * Place the order.
   *
   * Sends quantities and who the buyer is. No amount is sent, because none
   * would be read — the server prices the basket again from its own rows and
   * charges that.
   *
   * Two outcomes. A payable order comes back with somewhere to pay and the
   * browser goes there. A free one — a comp, a full-value code, a free event —
   * is already fulfilled, because there was never a gateway to involve.
   */
  placeOrder(): void {
    if (this.placing()) return;

    const name = this.buyer.name.trim();
    const email = this.buyer.email.trim();

    if (!name || !email) {
      this.orderError.set('Your name and email are needed to send the tickets.');
      return;
    }

    this.placing.set(true);
    this.orderError.set(null);

    this.api
      .order(
        this.event()!.slug,
        this.items(),
        { name, email, phone: this.buyer.phone.trim() || undefined },
        this.code().trim() || undefined,
        this.ref ?? undefined,
      )
      .subscribe({
        next: (order) => {
          if (order.payment?.redirect_url) {
            // Leaving the app entirely. The return is cosmetic — a signed
            // webhook decides whether this order is paid, not the redirect.
            window.location.href = order.payment.redirect_url;
            return;
          }

          this.router.navigate(['/order', order.reference]);
        },
        error: (response) => {
          this.placing.set(false);
          // Said plainly, because the worry at this moment is whether money
          // has moved.
          this.orderError.set(
            response?.error?.message ?? 'That order could not be placed. Nothing has been charged.',
          );
        },
      });
  }
}
