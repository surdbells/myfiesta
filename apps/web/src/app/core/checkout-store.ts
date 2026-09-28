import { Injectable, computed, signal } from '@angular/core';
import type { AccessUnlock } from './api.types';

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

  /**
   * The things that are not tickets: a table, a bottle, a shirt.
   *
   * Kept apart from the tickets rather than in one map, because the two are
   * different ids in different tables and the server takes them as two
   * lists. One map keyed by id would work right up until an add-on and a
   * ticket type shared one.
   */
  readonly addOns = signal<Record<string, number>>({});
  readonly code = signal('');

  /**
   * A presale code and the tiers it opened.
   *
   * The tiers are kept, not just the code: a hidden tier is not on the event
   * page's list, so without them the next page could not name what is in the
   * basket.
   */
  readonly access = signal<AccessUnlock | null>(null);

  /** A promoter's ref, captured on the event page and carried to the order. */
  readonly ref = signal<string | null>(null);

  /**
   * The order the last press placed, when its payment page could not be
   * opened (the API's 502 names it).
   *
   * Sent back with the next press, which takes over its hold rather than
   * holding the same places a second time. Kept with the basket, because a
   * reload, or going back to change the tickets, is still the same attempt.
   */
  readonly unpaid = signal<string | null>(null);

  readonly lines = computed(() =>
    Object.entries(this.items())
      .filter(([, quantity]) => quantity > 0)
      .map(([ticket_type_id, quantity]) => ({ ticket_type_id, quantity })),
  );

  readonly addOnLines = computed(() =>
    Object.entries(this.addOns())
      .filter(([, quantity]) => quantity > 0)
      .map(([add_on_id, quantity]) => ({ add_on_id, quantity })),
  );

  /** Tickets, and only tickets: this is what gates the way to checkout. */
  readonly count = computed(() =>
    Object.values(this.items()).reduce((sum, quantity) => sum + quantity, 0),
  );

  loadFor(slug: string): void {
    if (this.slug() === slug) return;

    this.slug.set(slug);
    this.items.set(this.read(slug)?.items ?? {});
    this.addOns.set(this.read(slug)?.addOns ?? {});
    this.code.set(this.read(slug)?.code ?? '');
    this.access.set(this.read(slug)?.access ?? null);
    this.unpaid.set(this.read(slug)?.unpaid ?? null);
  }

  setAccess(slug: string, access: AccessUnlock | null): void {
    this.loadFor(slug);
    this.access.set(access);

    // Taking the code away takes away what only it could buy.
    if (access === null) {
      const locked = new Set(this.lockedIds());
      this.items.set(Object.fromEntries(Object.entries(this.items()).filter(([id]) => !locked.has(id))));
    }

    this.persist(slug);
  }

  private lockedIds(): string[] {
    return this.read(this.slug() ?? '')?.access?.ticket_types.map((t) => t.id) ?? [];
  }

  setQuantity(slug: string, ticketTypeId: string, quantity: number): void {
    this.loadFor(slug);
    this.items.set({ ...this.items(), [ticketTypeId]: quantity });
    this.persist(slug);
  }

  setAddOnQuantity(slug: string, addOnId: string, quantity: number): void {
    this.loadFor(slug);
    this.addOns.set({ ...this.addOns(), [addOnId]: quantity });
    this.persist(slug);
  }

  /** The order a failed payment page left behind, or null once it is dealt with. */
  setUnpaid(slug: string, reference: string | null): void {
    this.loadFor(slug);
    this.unpaid.set(reference);
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
    this.addOns.set({});
    this.code.set('');
    this.access.set(null);
    this.unpaid.set(null);
    try {
      sessionStorage.removeItem(this.key(slug));
    } catch {
      // Storage can be absent (SSR, blocked); the in-memory copy was cleared.
    }
  }

  private key(slug: string): string {
    return `myfiesta.basket.${slug}`;
  }

  private read(slug: string): {
    items: Record<string, number>;
    addOns?: Record<string, number>;
    code: string;
    access?: AccessUnlock | null;
    unpaid?: string | null;
  } | null {
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
        JSON.stringify({
          items: this.items(),
          addOns: this.addOns(),
          code: this.code(),
          access: this.access(),
          unpaid: this.unpaid(),
        }),
      );
    } catch {
      // The basket still works for this page; it just will not survive one.
    }
  }
}
