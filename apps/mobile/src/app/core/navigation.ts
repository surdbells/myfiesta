import { Injectable, inject, signal } from '@angular/core';
import { NavigationEnd, NavigationStart, Router } from '@angular/router';

/** Which way a screen change goes, which decides how it moves. */
export type NavDirection = 'forward' | 'back' | 'switch' | 'none';

/** The places the bottom bar goes. Moving between them is a switch, not a push. */
const TAB_ROOTS = new Set(['/', '/manage', '/events', '/tickets', '/settings']);

function path(url: string): string {
  return url.split(/[?#]/)[0] || '/';
}

/**
 * Where the app is going, and how to go back.
 *
 * Ionic's outlet used to own this, and it kept one idea of it the screens could
 * not reach: every screen's back arrow navigated *forward* to a fixed parent,
 * so an event opened from Saved went "back" to Home and the history only ever
 * grew. Here, back is back — the previous screen if the app has one, the
 * screen's own parent only when it was opened straight from a link.
 *
 * It also tells the transitions which way to slide, and remembers where each
 * list was scrolled to so going back lands on the row that was tapped.
 */
@Injectable({ providedIn: 'root' })
export class Navigation {
  private readonly router = inject(Router);

  /** How many screens the app itself could pop. Zero at a cold start or a deep link. */
  private depth = 0;

  /** Set by back(), read when the navigation it causes starts. */
  private goingBack = false;

  /** The screen on top, and what its back arrow does. */
  private backAction: { owner: object; run: () => void } | null = null;

  /** Where each screen was scrolled to, by address. */
  private readonly scrolls = new Map<string, number>();

  readonly direction = signal<NavDirection>('none');
  readonly url = signal('/');

  constructor() {
    let from = '/';

    this.router.events.subscribe((event) => {
      if (event instanceof NavigationStart) {
        // The first screen is where the app opened, deep link or not. Nothing
        // is underneath it, and counting it would have back leave the app.
        if (event.id === 1) {
          this.direction.set('none');
          return;
        }

        const to = path(event.url);
        const popped = event.navigationTrigger === 'popstate' || this.goingBack;

        if (popped) this.direction.set('back');
        else if (TAB_ROOTS.has(to) && TAB_ROOTS.has(from)) this.direction.set('switch');
        else if (to === from) this.direction.set('none');
        else this.direction.set('forward');

        // A replacing navigation leaves the depth where it was.
        const replacing = this.router.getCurrentNavigation()?.extras.replaceUrl === true;

        if (popped) this.depth = Math.max(0, this.depth - 1);
        else if (!replacing && to !== from) this.depth += 1;

        this.goingBack = false;
      }

      if (event instanceof NavigationEnd) {
        from = path(event.urlAfterRedirects);
        this.url.set(event.urlAfterRedirects);
      }
    });
  }

  /** Whether the screen underneath this one is part of the app. */
  canPop(): boolean {
    return this.depth > 0;
  }

  /**
   * Go back: to the previous screen if there is one, else to `fallback`.
   *
   * The fallback is for a screen opened from outside — a shared link, a
   * notification — where the page underneath is not the app at all, and
   * history.back() would close it.
   */
  back(fallback: string | unknown[]): void {
    this.goingBack = true;

    if (this.canPop()) {
      history.back();
      return;
    }

    const commands = typeof fallback === 'string' ? [fallback] : fallback;
    void this.router.navigate(commands, { replaceUrl: true });
  }

  // --- the screen on top ------------------------------------------------------

  /**
   * A screen with a back arrow says what it does, so the phone's own back — the
   * Android button, the iOS edge swipe — does the same thing as the arrow.
   */
  claimBack(owner: object, run: () => void): void {
    this.backAction = { owner, run };
  }

  /** Only lets go if it still holds it: the next screen claims before this one leaves. */
  releaseBack(owner: object): void {
    if (this.backAction?.owner === owner) this.backAction = null;
  }

  /** Run the top screen's back, if it has one. */
  goBack(): boolean {
    if (!this.backAction) return false;

    this.backAction.run();

    return true;
  }

  isTabRoot(url = this.url()): boolean {
    return TAB_ROOTS.has(path(url));
  }

  // --- scroll positions -------------------------------------------------------

  rememberScroll(url: string, top: number): void {
    this.scrolls.set(url, top);
  }

  /** Where to scroll to, only when arriving by going back. */
  savedScroll(url: string): number | null {
    return this.direction() === 'back' ? (this.scrolls.get(url) ?? null) : null;
  }
}
