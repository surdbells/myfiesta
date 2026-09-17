import { Component, computed, inject } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { IonApp, IonRouterOutlet } from '@ionic/angular';
import { App as CapacitorApp } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import { MfTabs, MfToasts, SheetStack } from './ui';
import { SessionStore } from './core/session';

/**
 * The shell: an Ionic router outlet, the tab bar, the app's toasts, and the
 * two things a phone needs that a browser does not — a back button with
 * somewhere to go, and a theme that matches the phone before the first frame.
 */
@Component({
  selector: 'mf-root',
  imports: [IonApp, IonRouterOutlet, MfToasts, MfTabs],
  template: `
    <ion-app>
      <div class="frame">
        <ion-router-outlet />

        @if (showTabs()) {
          <mf-tabs [tabs]="tabs()" />
        }
      </div>

      <mf-toasts />
    </ion-app>
  `,
  styles: `
    ion-app {
      /* Ionic paints its own background behind the outlet; in dark mode that
         is a white flash between screens without this. */
      background: var(--surface-sunken);
    }

    .frame {
      display: grid;
      grid-template-rows: minmax(0, 1fr) auto;
      height: 100%;
    }

    /* The outlet positions its pages absolutely, so it needs a box of its own
       to be absolute inside — otherwise the pages sit over the tab bar. */
    ion-router-outlet {
      position: relative;
    }
  `,
})
export class App {
  private readonly session = inject(SessionStore);
  private readonly sheets = inject(SheetStack);
  private readonly router = inject(Router);

  /** Where the app is now, so the bar can hide on screens that own the phone. */
  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: '/' },
  );

  /**
   * The bar is for moving between places, so it is absent everywhere moving is
   * not the point: signing in, a door pass, and the full-screen ticket
   * somebody is holding up at a door.
   */
  readonly showTabs = computed(() => {
    if (this.session.locked()) return false;

    return !/^\/(sign-in|join|forgotten-password|door|door-pass|ui|tickets\/.)/.test(this.url());
  });

  readonly tabs = computed(() => [
    { link: '/', label: "What's on", glyph: 'home', exact: true },
    ...(this.session.canSeeSales() ? [{ link: '/events', label: 'Your events', glyph: 'events' }] : []),
    { link: '/tickets', label: 'Tickets', glyph: 'ticket' },
    { link: '/settings', label: 'You', glyph: 'person' },
  ]);

  constructor() {
    // The session and theme are already restored: app.config does it before
    // the first route resolves. What is left is the door pass, which owns its
    // phone wherever the app was when it was last closed.
    if (this.session.locked()) void this.router.navigate(['/door'], { replaceUrl: true });

    this.wireBackButton();
  }

  /**
   * Back closes what is open before it leaves anything.
   *
   * Android sends one event for the whole app, so the order has to be decided
   * here: a sheet, then a screen with history, and only then the app itself.
   * Exiting from a ticket screen because a sheet was open reads as a crash.
   */
  private wireBackButton(): void {
    if (!Capacitor.isNativePlatform()) return;

    void CapacitorApp.addListener('backButton', ({ canGoBack }) => {
      if (this.sheets.dismissTop()) return;

      // A door pass has one screen and no way out but ending the shift.
      if (this.session.locked()) return;

      if (canGoBack) window.history.back();
      else void CapacitorApp.exitApp();
    });
  }
}
