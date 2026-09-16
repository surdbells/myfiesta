import { DOCUMENT } from '@angular/common';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { StatusBar, Style } from '@capacitor/status-bar';
import { Preferences } from '@capacitor/preferences';

export type ThemeChoice = 'system' | 'light' | 'dark';

const KEY = 'myfiesta.theme';

/**
 * Light and dark, and the phone's own setting as the default.
 *
 * Three states rather than two. "System" is what most people leave it on, and
 * an app that only toggles between light and dark is one that ignores a phone
 * set to go dark at sunset — which for a product used at night is the setting
 * that matters most.
 *
 * The choice is written to `data-theme` on the document, which is the same
 * switch the web apps use: the token stylesheet already redefines every colour
 * under `@media (prefers-color-scheme: dark)` and under `[data-theme="dark"]`,
 * so nothing here knows a single colour.
 *
 * The native status bar is told separately. It sits outside the WebView, so a
 * dark app with black icons on a black bar is a strip of nothing at the top of
 * the screen until it is set.
 */
@Injectable({ providedIn: 'root' })
export class Theme {
  private readonly document = inject(DOCUMENT);

  readonly choice = signal<ThemeChoice>('system');

  /** What is actually on screen once "system" has been resolved. */
  readonly resolved = computed<'light' | 'dark'>(() => {
    const choice = this.choice();

    if (choice !== 'system') return choice;

    return this.prefersDark() ? 'dark' : 'light';
  });

  private readonly prefersDark = signal(false);

  constructor() {
    const view = this.document.defaultView;
    // jsdom, and any other host without a media-query engine, simply stays
    // light until told otherwise.
    const media = typeof view?.matchMedia === 'function' ? view.matchMedia('(prefers-color-scheme: dark)') : null;

    if (media) {
      this.prefersDark.set(media.matches);
      media.addEventListener('change', (event) => {
        this.prefersDark.set(event.matches);
        void this.paint();
      });
    }
  }

  /** Read the saved choice. Called once, before the first paint. */
  async restore(): Promise<void> {
    try {
      const { value } = await Preferences.get({ key: KEY });

      if (value === 'light' || value === 'dark' || value === 'system') {
        this.choice.set(value);
      }
    } catch {
      // No storage on this device, or a private profile. The phone's own
      // setting is a sound default to fall back to.
    }

    await this.paint();
  }

  async set(choice: ThemeChoice): Promise<void> {
    this.choice.set(choice);

    try {
      await Preferences.set({ key: KEY, value: choice });
    } catch {
      // It applies for this run either way.
    }

    await this.paint();
  }

  private async paint(): Promise<void> {
    const root = this.document.documentElement;
    const choice = this.choice();

    // Nothing stamped for "system", so the media query decides — the same
    // three states the web apps have.
    if (choice === 'system') root.removeAttribute('data-theme');
    else root.setAttribute('data-theme', choice);

    if (!Capacitor.isPluginAvailable('StatusBar')) return;

    try {
      // Style.Dark means dark *content* on the bar, which belongs over a light
      // app. Naming it the other way round is the classic mistake here.
      await StatusBar.setStyle({ style: this.resolved() === 'dark' ? Style.Light : Style.Dark });
    } catch {
      // Not every platform has a status bar to set (the browser, for one).
    }
  }
}
