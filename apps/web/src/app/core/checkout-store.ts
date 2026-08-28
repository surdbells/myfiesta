import { Injectable, computed, signal } from '@angular/core';

/**
 * The basket, between pages.
 *
 * Selection happens on one route and paying on another, so the choice has to
 * survive the navigation — and a refresh, because "back to change my
 * tickets" is a refresh in disguise. sessionStorage, not localStorage: a
 * basket that reappears next week is not a convenience, it is a stale price
 * list. Only quantities are kept; every figure is re-quoted by the server on
 * each page that shows one.
 */
@Injectable({ providedIn: 'root' })
export class CheckoutStore {
  readonly slug = signal<string | null>(null);
  readonly items = signal<Record<string, number>>({});
  readonly code = signal('');

  /** A promoter's ref, captured on the event page and carried to the order. */
  readonly ref = signal<string | null>(null);

  readonly lines = computed(() =>
    Object.entries(this.items())
      .filter(([, quantity]) => quantity > 0)
      .map(([ticket_type_id, quantity]) => ({ ticket_type_id, quantity })),
  );

  readonly count = computed(() =>
    Object.values(this.items()).reduce((sum, quantity) => sum + quantity, 0),
  );

  loadFor(slug: string): void {
    if (this.slug() === slug) return;

    this.slug.set(slug);
    this.items.set(this.read(slug)?.items ?? {});
    this.code.set(this.read(slug)?.code ?? '');
  }

  setQuantity(slug: string, ticketTypeId: string, quantity: number): void {
    this.loadFor(slug);
    this.items.set({ ...this.items(), [ticketTypeId]: quantity });
    this.persist(slug);
  }

  setCode(slug: string, code: string): void {
    this.loadFor(slug);
    this.code.set(code);
    this.persist(slug);
  }

  clear(slug: string): void {
    this.slug.set(null);
    this.items.set({});
    this.code.set('');
    try {
      sessionStorage.removeItem(this.key(slug));
    } catch {
      // Storage can be absent (SSR, blocked); the in-memory copy was cleared.
    }
  }

  private key(slug: string): string {
    return `myfiesta.basket.${slug}`;
  }

  private read(slug: string): { items: Record<string, number>; code: string } | null {
    try {
      const raw = sessionStorage.getItem(this.key(slug));
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  private persist(slug: string): void {
    try {
      sessionStorage.setItem(
        this.key(slug),
        JSON.stringify({ items: this.items(), code: this.code() }),
      );
    } catch {
      // The basket still works for this page; it just will not survive one.
    }
  }
}
