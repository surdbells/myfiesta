import { Injectable, inject, signal } from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { NavigationEnd, NavigationStart, Router } from '@angular/router';

/** Everything an embedded page says to the page around it. Nothing personal. */
export type EmbedMessage =
  | { type: 'resize'; height: number }
  | { type: 'paid'; event: string; tickets: number };

/** The prefix every embedded page lives under — the only paths that may be framed. */
export const EMBED_PREFIX = 'embed';

/**
 * Whether this page is being shown inside somebody else's.
 *
 * An organizer with a venue website puts our tickets on it with one script
 * tag. The buying steps are the same pages as on myFiesta itself; what changes
 * is around them. No site header or footer, links that would leave the flow
 * open in a new tab, the height is reported so the frame fits, and paying
 * happens in a tab of its own — card processors refuse to be framed, and a
 * card form inside a frame on a page we do not control is not something a
 * buyer should be asked to trust anyway.
 *
 * Known from the address rather than from a flag somewhere: every embedded
 * page is under /embed/, so a refresh inside the frame stays embedded, and the
 * server can let exactly those paths be framed and refuse it everywhere else.
 */
@Injectable({ providedIn: 'root' })
export class EmbedMode {
  private readonly document = inject(DOCUMENT);

  readonly active = signal(false);

  constructor() {
    const router = inject(Router);

    // At the start, not the end: the page being navigated to is built before
    // the navigation ends, and it asks which links to draw as it is built.
    router.events.subscribe((event) => {
      if (event instanceof NavigationStart) this.active.set(isEmbedded(event.url));
      if (event instanceof NavigationEnd) this.active.set(isEmbedded(event.urlAfterRedirects));
    });
  }

  /** The ticket-choosing step, here or inside a frame. */
  tickets(slug: string): string[] {
    return this.active() ? ['/', EMBED_PREFIX, slug] : ['/', slug, 'tickets'];
  }

  checkout(slug: string): string[] {
    return this.active() ? ['/', EMBED_PREFIX, slug, 'checkout'] : ['/', slug, 'checkout'];
  }

  order(reference: string): string[] {
    return this.active() ? ['/', EMBED_PREFIX, 'order', reference] : ['/order', reference];
  }

  /**
   * Tell the page around us something.
   *
   * To any origin: the organizer's site is wherever they put the script, and
   * nothing sent here is anything that page could not already see on screen.
   */
  tell(message: EmbedMessage): void {
    const view = this.document.defaultView;

    if (!this.active() || !view || view.parent === view) return;

    view.parent.postMessage({ source: 'myfiesta', ...message }, '*');
  }
}

export function isEmbedded(url: string): boolean {
  return url === `/${EMBED_PREFIX}` || url.startsWith(`/${EMBED_PREFIX}/`);
}

/**
 * Remember where the payment page was, for a buyer whose browser stopped the
 * new tab opening. sessionStorage: this frame, this visit, and gone after.
 */
const PAYMENT_KEY = 'myfiesta.embed.payment.';

export function rememberPayment(reference: string, url: string): void {
  try {
    sessionStorage.setItem(PAYMENT_KEY + reference, url);
  } catch {
    // Storage refused inside a third-party frame: the order page then offers
    // the event page instead, which still ends in the same payment.
  }
}

export function paymentFor(reference: string): string | null {
  try {
    return sessionStorage.getItem(PAYMENT_KEY + reference);
  } catch {
    return null;
  }
}

/**
 * Count a look at an event once per browsing session.
 *
 * Flicking from the event to its tickets and back is one person looking, not
 * three, and a reload is not a second visitor. Only in the browser: a
 * crawler reading the server-rendered page is not somebody who looked.
 */
export function viewedOnce(slug: string, embed: boolean): boolean {
  if (typeof window === 'undefined') return false;

  const key = `myfiesta.viewed.${embed ? 'embed.' : ''}${slug}`;

  try {
    if (sessionStorage.getItem(key)) return false;
    sessionStorage.setItem(key, '1');
  } catch {
    // Storage refused: count it, once per page load, rather than never.
  }

  return true;
}
