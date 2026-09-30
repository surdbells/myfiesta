import { Injectable, inject, signal } from '@angular/core';
import { CanActivateFn, RedirectCommand, Router } from '@angular/router';
import { App as CapacitorApp } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import { Preferences } from '@capacitor/preferences';
import { SessionStore } from './session';

const KEY = 'myfiesta.intro';

/** The introduction's address: its route in app.routes.ts, and the shell's list of screens without the bottom bar. */
export const INTRO_PATH = '/welcome';

/**
 * Whether this launch opens on the introduction.
 *
 * Once per phone: the first launch shows it, and finishing or skipping it is
 * remembered, so it is never in the way of somebody who has already seen it.
 * Settings opens it again on request, and so does signing in, for somebody
 * without an account to reach Settings with.
 *
 * Never for somebody who arrived on a purpose. A launch from a link — an
 * event shared in a chat, the door pass for tonight — goes straight where it
 * was sent, and the introduction waits for a launch of the person's own. That
 * is decided twice over, because the link can reach the app before or after
 * the first screen does: here, from the link the operating system started the
 * app with, and in DeepLinks, which steps the introduction aside for a link
 * that arrives while it is on screen.
 */
@Injectable({ providedIn: 'root' })
export class Intro {
  private readonly session = inject(SessionStore);

  /** True when the first screen of this launch should be the introduction. */
  readonly due = signal(false);

  /**
   * Read what the phone remembers. Called once, from app.config, before the
   * first screen — and after the session, which it asks about.
   */
  async restore(): Promise<void> {
    try {
      const { value } = await Preferences.get({ key: KEY });

      if (value === 'seen') return;
    } catch {
      // Storage that cannot be read cannot remember a skip either, and an
      // introduction on every launch is worse than none.
      return;
    }

    // An account already signed in on this phone is somebody who has used
    // the app, updating to the version that brought this in: a fresh install
    // has no session to restore. Remembered as seen, so signing out later
    // does not bring it up either. Not a door pass, which can be the first
    // thing a phone ever did with the app, and ends with the night.
    if (this.session.signedIn() && !this.session.locked()) {
      await this.finish();

      return;
    }

    if (await this.launchedFromLink()) return;

    this.due.set(true);
  }

  /** Finished or skipped: not shown again unless asked for. */
  async finish(): Promise<void> {
    this.due.set(false);

    try {
      await Preferences.set({ key: KEY, value: 'seen' });
    } catch {
      // It is gone for this run either way.
    }
  }

  /**
   * A link arrived: not now, and not remembered as seen — the next launch of
   * the person's own still gets it.
   */
  postpone(): void {
    this.due.set(false);
  }

  /**
   * Whether the operating system started the app to open a link.
   *
   * Only on a phone: in a browser the address bar is the link, and a first
   * screen other than What's on never meets the introduction at all.
   */
  private async launchedFromLink(): Promise<boolean> {
    if (!Capacitor.isNativePlatform()) return false;

    try {
      const launch = await CapacitorApp.getLaunchUrl();

      return !!launch?.url;
    } catch {
      return false;
    }
  }
}

/**
 * What's on's guard: a first launch opens on the introduction instead.
 *
 * Only the first screen of a launch. Once the app has shown anything, What's
 * on is What's on — a person who arrived by a link and then tapped the tab
 * gets the tab, and the introduction the next time they open the app. Never
 * on a door pass, whose phone has one screen.
 */
export const introFirst: CanActivateFn = () => {
  const intro = inject(Intro);
  const router = inject(Router);

  if (!intro.due() || router.navigated || inject(SessionStore).locked()) return true;

  // In place of What's on rather than on top of it: nothing is underneath
  // the first screen of a launch, for back or Skip to return to.
  return new RedirectCommand(router.createUrlTree([INTRO_PATH]), { replaceUrl: true });
};
