import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
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
  imports: [RouterLink, FormsModule, CheckoutSteps],
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

  /** The presale code field: closed until asked for, since most buyers have none. */
  readonly accessOpen = signal(false);
  readonly accessInput = signal('');
  readonly accessError = signal<string | null>(null);
  readonly unlocking = signal(false);

  /**
   * The tiers, with whatever a presale code opened merged in.
   *
   * Buyable first, then the ones waiting to open, then the done ones — a
   * sold-out tier stays on the page, dimmed, because "gone" is information.
   * Hidden stays hidden unless a code opened it.
   */
  readonly tiers = computed(() => {
    const unlocked = this.store.access()?.ticket_types ?? [];
    const unlockedIds = new Set(unlocked.map((t) => t.id));
    const publicTiers = (this.event()?.ticket_types ?? []).filter((t) => t.status !== 'hidden' && !unlockedIds.has(t.id));

    const rank = (t: TicketType) => (this.buyable(t) ? 0 : this.opensLater(t) ? 1 : 2);

    return [...unlocked, ...publicTiers].sort((a, b) => rank(a) - rank(b));
  });

  constructor() {
    this.store.loadFor(this.slug);

    // A presale link: /{slug}/tickets?access=CODE opens the tiers straight away.
    const shared = this.route.snapshot.queryParamMap.get('access');
    if (shared && shared.toUpperCase() !== this.store.access()?.code) {
      this.accessInput.set(shared);
      this.accessOpen.set(true);
      this.unlock();
    }

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

  /** Opened by the presale code this buyer holds. */
  isUnlocked(type: TicketType): boolean {
    return this.store.access()?.ticket_types.some((t) => t.id === type.id) ?? false;
  }

  /** Before its sales open and not opened by a code. */
  opensLater(type: TicketType): boolean {
    return (
      !this.isUnlocked(type) &&
      type.status === 'on_sale' &&
      !!type.sales_start_at &&
      new Date(type.sales_start_at) > new Date()
    );
  }

  salesEnded(type: TicketType): boolean {
    return !!type.sales_end_at && new Date(type.sales_end_at) <= new Date();
  }

  /** Whether the steppers work: the same rules the server prices by. */
  buyable(type: TicketType): boolean {
    if (this.salesEnded(type)) return false;
    if (this.isUnlocked(type)) return type.status === 'on_sale' || type.status === 'hidden';

    return type.status === 'on_sale' && !this.opensLater(type);
  }

  /** "Fri 19 Sep, 10:00 a.m." in the event's own zone. */
  opensAt(type: TicketType): string {
    const zone = this.event()?.timezone;

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
      ...(zone ? { timeZone: zone } : {}),
    }).format(new Date(type.sales_start_at!));
  }

  unlock(): void {
    const code = this.accessInput().trim();
    if (!code || this.unlocking()) return;

    this.unlocking.set(true);
    this.accessError.set(null);

    this.api.unlock(this.slug, code).subscribe({
      next: (access) => {
        this.unlocking.set(false);
        this.store.setAccess(this.slug, access);
        this.accessOpen.set(false);
        this.accessInput.set('');
        this.refreshQuote();
      },
      error: (response) => {
        this.unlocking.set(false);
        this.accessError.set(
          response?.status === 429
            ? 'Too many tries. Wait a minute and try again.'
            : (response?.error?.message ?? 'That code could not be checked. Try again.'),
        );
      },
    });
  }

  removeAccess(): void {
    this.store.setAccess(this.slug, null);
    this.refreshQuote();
  }

  adjust(type: TicketType, delta: number): void {
    if (!this.buyable(type) && delta > 0) return;

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

    this.api.quote(this.slug, lines, undefined, this.store.ref() ?? undefined, this.store.access()?.code).subscribe({
      next: (quote) => this.quote.set(quote),
      error: () => this.quote.set(null),
    });
  }
}
