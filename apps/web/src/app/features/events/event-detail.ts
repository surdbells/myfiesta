import { CommonModule } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
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
  imports: [CommonModule],
  templateUrl: './event-detail.html',
  styleUrl: './event-detail.css',
})
export class EventDetail {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly seo = inject(Seo);

  readonly event = signal<EventDetailModel | null>(null);
  readonly notFound = signal(false);
  readonly quote = signal<Quote | null>(null);
  readonly quoteError = signal<string | null>(null);

  /** ticket type id -> quantity */
  readonly basket = signal<Record<string, number>>({});

  /** Captured from the link and carried to the order, so a promoter gets credit. */
  private ref: string | null = null;

  readonly formatMoney = formatMoney;

  readonly hasSelection = computed(() =>
    Object.values(this.basket()).some((q) => q > 0),
  );

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
      return;
    }

    this.api.quote(this.event()!.slug, items, undefined, this.ref ?? undefined).subscribe({
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
}
