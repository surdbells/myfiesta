import { Injectable, signal } from '@angular/core';

/**
 * Saved events, kept in this browser.
 *
 * There are no buyer accounts — checkout is a guest flow on purpose — so the
 * heart is a bookmark, not a synced favourite, and localStorage is exactly
 * that. It can be empty again in a private window; every read is best-effort.
 */
@Injectable({ providedIn: 'root' })
export class Saves {
  private readonly key = 'myfiesta.saved';

  readonly slugs = signal<ReadonlySet<string>>(this.read());

  has(slug: string): boolean {
    return this.slugs().has(slug);
  }

  toggle(slug: string): void {
    const next = new Set(this.slugs());

    if (next.has(slug)) {
      next.delete(slug);
    } else {
      next.add(slug);
    }

    this.slugs.set(next);

    try {
      localStorage.setItem(this.key, JSON.stringify([...next]));
    } catch {
      // The heart still works for this visit.
    }
  }

  private read(): Set<string> {
    try {
      return new Set(JSON.parse(localStorage.getItem(this.key) ?? '[]'));
    } catch {
      return new Set();
    }
  }
}
