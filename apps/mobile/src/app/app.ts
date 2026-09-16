import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { IonApp, IonRouterOutlet } from '@ionic/angular';
import { App as CapacitorApp } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import { MfToasts, SheetStack } from './ui';
import { SessionStore } from './core/session';

/**
 * The shell: an Ionic router outlet, the app's toasts, and the two things a
 * phone needs that a browser does not — a back button with somewhere to go,
 * and a theme that matches the phone before the first frame.
 */
@Component({
  selector: 'mf-root',
  imports: [IonApp, IonRouterOutlet, MfToasts],
  template: `
    <ion-app>
      <ion-router-outlet />
      <mf-toasts />
    </ion-app>
  `,
  styles: `
    ion-app {
      /* Ionic paints its own background behind the outlet; in dark mode that
         is a white flash between screens without this. */
      background: var(--surface-sunken);
    }
  `,
})
export class App {
  private readonly session = inject(SessionStore);
  private readonly sheets = inject(SheetStack);
  private readonly router = inject(Router);

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
