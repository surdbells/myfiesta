import { Injectable, Injector, afterNextRender, inject } from '@angular/core';
import { NavigationEnd, NavigationError, Router } from '@angular/router';
import { Capacitor } from '@capacitor/core';
import { SplashScreen } from '@capacitor/splash-screen';

/**
 * The longest the launch screen stays up, whatever else happens.
 *
 * A first screen normally renders well inside a second. This is for the start
 * that goes wrong — storage that never answers, a chunk that fails to load —
 * where a splash that never leaves looks like a phone that has hung, and the
 * app's own ground with an error on it is at least something to act on.
 */
export const LAUNCH_SCREEN_LIMIT_MS = 4000;

/**
 * The native launch screen, handed over to the app.
 *
 * The operating system shows the mic on #0b0f0c from the tap (styles.xml on
 * Android, LaunchScreen.storyboard on iPhone), and @capacitor/splash-screen
 * keeps that picture up after the WebView has started, because
 * capacitor.config.ts gives its timer longer than this ever takes — the timer
 * is only for a start where none of this runs. This takes it down once the
 * first screen has actually rendered — not when the WebView has loaded, which
 * is an empty page, and not after a guessed delay, which is either a flash of
 * that empty page or a wait for nothing.
 *
 * The first screen is whichever the first navigation ends on: What's on, the
 * introduction on a first run, the scanner on a door pass.
 */
@Injectable({ providedIn: 'root' })
export class LaunchScreen {
  private readonly router = inject(Router);
  private readonly injector = inject(Injector);

  private down = false;
  private limit: ReturnType<typeof setTimeout> | null = null;

  /**
   * Called once, from app.config, before the first navigation starts — so its
   * end is not missed — and before anything that could hang, so the limit is
   * already running if something does.
   */
  holdUntilFirstScreen(): void {
    this.limit = setTimeout(() => this.hide(), LAUNCH_SCREEN_LIMIT_MS);

    const events = this.router.events.subscribe((event) => {
      if (event instanceof NavigationEnd) {
        events.unsubscribe();

        // Ended is not drawn: the screen is attached, and the change
        // detection that fills it in comes next. Taking the splash down
        // before that shows a frame of the empty page.
        afterNextRender(() => this.hide(), { injector: this.injector });
      } else if (event instanceof NavigationError) {
        // Nothing is coming. What the error handler shows beats the splash.
        events.unsubscribe();
        this.hide();
      }
    });
  }

  /** Takes the launch screen down. Once: later calls, and the limit, do nothing. */
  hide(): void {
    if (this.down) return;

    this.down = true;

    if (this.limit !== null) clearTimeout(this.limit);

    hideLaunchScreen();
  }
}

/**
 * The plugin's hide, where there is a plugin to ask.
 *
 * Also called by main.ts when the app fails to start at all, where there is no
 * injector to reach the service through. A browser has no launch screen — the
 * plugin's web half is a no-op, and only a chunk to fetch for nothing, so it is
 * never asked — and neither has a phone whose build did not link the plugin.
 * A refusal from the plugin leaves nothing to do but carry on.
 */
export function hideLaunchScreen(): void {
  if (!Capacitor.isNativePlatform() || !Capacitor.isPluginAvailable('SplashScreen')) return;

  void SplashScreen.hide().catch(() => undefined);
}
