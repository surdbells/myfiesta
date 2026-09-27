import { Component, computed, effect, inject } from '@angular/core';
import { Router, RouterOutlet } from '@angular/router';
import { App as CapacitorApp } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import { MfDialogHost, MfTabs, MfToasts, SheetStack } from './ui';
import { SessionStore } from './core/session';
import { Navigation } from './core/navigation';
import { Chrome } from './core/chrome';
import { DeepLinks } from './core/deep-links';

/**
 * The shell: the stage screens slide across, the bottom bar, the app's toasts,
 * and the two things a phone needs that a browser does not — a back that
 * always has somewhere sensible to go, and an edge swipe that does the same.
 *
 * No Ionic. Its outlet was the last piece of it on screen, and what it brought
 * — page transitions and a back stack — is done here with the platform's own
 * View Transitions and a navigation service the screens can actually reach.
 */
@Component({
  selector: 'mf-root',
  imports: [RouterOutlet, MfToasts, MfTabs, MfDialogHost],
  template: `
    <div class="frame" [class.with-tabs]="showTabs() && !chrome.barHidden()">
      <main class="stage" (touchstart)="edgeStart($event)" (touchend)="edgeEnd($event)">
        <router-outlet />
      </main>

      @if (showTabs()) {
        <mf-tabs [tabs]="tabs()" />
      }
    </div>

    <mf-toasts [style.--mf-toast-lift.px]="toastLift()" />
    <mf-dialog-host />
  `,
  styles: `
    :host {
      display: block;
      height: 100%;
      background: var(--surface-sunken);
    }

    /*
     * The bottom bar floats over the stage rather than taking a row, so lists
     * scroll under it. Screens leave room for it through this variable, which
     * is only the home indicator when the bar is not there.
     */
    .frame {
      position: relative;
      height: 100%;
      --mf-bar-space: var(--mf-safe-bottom);
    }

    .frame.with-tabs {
      --mf-bar-space: calc(var(--mf-tab-bar) + var(--mf-safe-bottom));
    }

    /*
     * The stage is what slides between screens; the bar over it stays put.
     * Each routed screen fills it — the outlet inserts screens as its own
     * siblings, so they are sized from here rather than from inside.
     */
    .stage {
      position: absolute;
      inset: 0;
      overflow: hidden;
    }

    .stage > :not(router-outlet) {
      position: absolute;
      inset: 0;
      display: block;
    }
  `,
})
export class App {
  private readonly session = inject(SessionStore);
  private readonly sheets = inject(SheetStack);
  private readonly router = inject(Router);
  private readonly nav = inject(Navigation);
  protected readonly chrome = inject(Chrome);

  /**
   * The bar is for moving between places, so it is absent everywhere moving is
   * not the point: signing in, a door pass, and the full-screen ticket
   * somebody is holding up at a door.
   */
  readonly showTabs = computed(() => {
    if (this.session.locked()) return false;

    return !/^\/(sign-in|join|forgotten-password|door|door-pass|ui|tickets\/.)/.test(this.nav.url());
  });

  /**
   * How far above the home indicator a toast sits: over the keyboard when it
   * is up, over the bottom bar when it shows, and over a screen's Save bar —
   * a "Saved." that covers the button just pressed reads as the button gone.
   */
  protected readonly toastLift = computed(() => {
    const footer = this.chrome.footerHeight();

    if (this.chrome.keyboard()) return this.chrome.keyboardHeight() + footer;

    const bar = this.showTabs() && !this.chrome.barHidden() && !this.chrome.barTucked() ? 64 : 0;

    return bar + footer;
  });

  readonly tabs = computed(() => [
    { link: '/', label: "What's on", glyph: 'home' as const, exact: true },
    ...(this.session.canSeeSales() ? [{ link: '/manage', label: 'Manage', glyph: 'manage' as const }] : []),
    { link: '/tickets', label: 'Tickets', glyph: 'ticket' as const },
    { link: '/settings', label: 'You', glyph: 'person' as const },
  ]);

  constructor() {
    // The session and theme are already restored: app.config does it before
    // the first route resolves. What is left is the door pass, which owns its
    // phone wherever the app was when it was last closed.
    if (this.session.locked()) void this.router.navigate(['/door'], { replaceUrl: true });

    // Every new screen starts with the bottom bar out.
    effect(() => {
      this.nav.url();
      this.chrome.reset();
    });

    this.wireBackButton();

    // Links to the site, opened here. After the door check above, so a door
    // pass is already locked when the first one arrives.
    inject(DeepLinks).listen();
  }

  /**
   * Back closes what is open before it leaves anything.
   *
   * Android sends one event for the whole app, so the order is decided here: a
   * sheet, then the screen's own back, then home, and only from home the app
   * itself. Exiting from a ticket screen because a sheet was open reads as a
   * crash.
   */
  private wireBackButton(): void {
    if (!Capacitor.isNativePlatform()) return;

    void CapacitorApp.addListener('backButton', () => {
      if (this.sheets.dismissTop()) return;

      // A door pass has one screen and no way out but ending the shift.
      if (this.session.locked()) return;

      if (this.nav.goBack()) return;

      if (this.nav.url() !== '/') {
        void this.router.navigate(['/']);
        return;
      }

      void CapacitorApp.exitApp();
    });
  }

  // --- the iOS edge swipe -------------------------------------------------------

  private edge: { x: number; y: number; at: number } | null = null;

  /**
   * iOS has no back button; the edge swipe is how people go back, and a WebView
   * without it feels like a web page in a frame. Android draws its own gesture
   * and sends it as the back button, so this is iOS only.
   */
  protected edgeStart(event: TouchEvent): void {
    if (Capacitor.getPlatform() !== 'ios' || this.sheets.depth().length > 0) return;

    const touch = event.touches[0];

    this.edge = touch.clientX <= 24 ? { x: touch.clientX, y: touch.clientY, at: event.timeStamp } : null;
  }

  protected edgeEnd(event: TouchEvent): void {
    if (!this.edge) return;

    const touch = event.changedTouches[0];
    const dx = touch.clientX - this.edge.x;
    const dy = Math.abs(touch.clientY - this.edge.y);
    const quick = event.timeStamp - this.edge.at < 400;

    this.edge = null;

    // Far enough, or quick enough, and more across than down.
    if ((dx > 90 || (quick && dx > 50)) && dy < dx / 2) this.nav.goBack();
  }
}
