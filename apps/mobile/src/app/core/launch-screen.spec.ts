import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { NavigationEnd, Router, provideRouter } from '@angular/router';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const plugin = vi.hoisted(() => ({
  native: true,
  available: true,
  hide: vi.fn(async () => undefined),
}));

vi.mock('@capacitor/splash-screen', () => ({
  SplashScreen: { hide: plugin.hide },
}));

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isPluginAvailable: (name: string) => name === 'SplashScreen' && plugin.available,
    isNativePlatform: () => plugin.native,
    getPlatform: () => 'android',
  },
  registerPlugin: () => ({}),
  WebPlugin: class {},
}));

import { LAUNCH_SCREEN_LIMIT_MS, LaunchScreen, hideLaunchScreen } from './launch-screen';

@Component({ template: '<h1>What’s on</h1>' })
class FirstScreen {}

/**
 * The hand-over from the native launch screen to the app.
 *
 * capacitor.config.ts gives the plugin's own timer longer than this takes, so
 * on any start the app survives, this is what takes the splash down. Two ways
 * to get it wrong, and both are invisible in a browser: too early is a frame
 * of empty WebView at every launch, and never is an app that looks hung for
 * good.
 */
describe('the launch screen', () => {
  beforeEach(() => {
    plugin.native = true;
    plugin.available = true;
    plugin.hide.mockClear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', component: FirstScreen },
          { path: 'broken', loadComponent: () => Promise.reject(new Error('The chunk did not load')) },
        ]),
      ],
    });
  });

  afterEach(() => vi.useRealTimers());

  it('stays up while the app starts, with nothing rendered yet', async () => {
    TestBed.inject(LaunchScreen).holdUntilFirstScreen();

    await Promise.resolve();

    expect(plugin.hide).not.toHaveBeenCalled();
  });

  it('comes down once the first screen has rendered — not when its navigation merely ends', async () => {
    const router = TestBed.inject(Router);
    const whenEnded: number[] = [];

    TestBed.inject(LaunchScreen).holdUntilFirstScreen();

    // Subscribed after the service, so it sees the NavigationEnd the service
    // has just seen: the splash must still be up at that moment.
    router.events.subscribe((event) => {
      if (event instanceof NavigationEnd) whenEnded.push(plugin.hide.mock.calls.length);
    });

    await router.navigateByUrl('/');
    TestBed.tick();

    expect(whenEnded).toEqual([0]);
    expect(plugin.hide).toHaveBeenCalledTimes(1);
  });

  it('comes down once only: the limit does nothing after the first screen', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const router = TestBed.inject(Router);

    TestBed.inject(LaunchScreen).holdUntilFirstScreen();
    await router.navigateByUrl('/');
    TestBed.tick();

    vi.advanceTimersByTime(LAUNCH_SCREEN_LIMIT_MS * 2);

    // A second navigation is not a first screen.
    await router.navigateByUrl('/?again=1');
    TestBed.tick();

    expect(plugin.hide).toHaveBeenCalledTimes(1);
  });

  it('comes down at the limit when no first screen arrives, so a start that hangs is not a splash for good', () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });

    TestBed.inject(LaunchScreen).holdUntilFirstScreen();

    vi.advanceTimersByTime(LAUNCH_SCREEN_LIMIT_MS - 1);
    expect(plugin.hide).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    expect(plugin.hide).toHaveBeenCalledTimes(1);
  });

  it('comes down at once when the first navigation fails', async () => {
    TestBed.inject(LaunchScreen).holdUntilFirstScreen();

    await TestBed.inject(Router)
      .navigateByUrl('/broken')
      .catch(() => undefined);

    expect(plugin.hide).toHaveBeenCalledTimes(1);
  });

  it('asks nothing of a browser, which has no launch screen', async () => {
    // The plugin's web half is registered, so a browser counts it as
    // available: being on a phone is what has to be asked.
    plugin.native = false;

    TestBed.inject(LaunchScreen).holdUntilFirstScreen();
    await TestBed.inject(Router).navigateByUrl('/');
    TestBed.tick();
    hideLaunchScreen();

    expect(plugin.hide).not.toHaveBeenCalled();
  });

  it('asks nothing of a phone whose build did not link the plugin', async () => {
    plugin.available = false;

    TestBed.inject(LaunchScreen).holdUntilFirstScreen();
    await TestBed.inject(Router).navigateByUrl('/');
    TestBed.tick();

    expect(plugin.hide).not.toHaveBeenCalled();
  });

  it('carries on when the plugin refuses', async () => {
    plugin.hide.mockRejectedValueOnce(new Error('No splash to hide'));

    expect(() => hideLaunchScreen()).not.toThrow();
    await Promise.resolve();

    expect(plugin.hide).toHaveBeenCalledTimes(1);
  });

  /*
   * What the native side is told. Read from the files rather than built:
   * nothing here builds the native projects, and a mistake in either is a
   * phone stuck on its splash, found on the phone.
   */
  describe('natively', () => {
    const ROOT = process.cwd();

    it('keeps the plugin’s own timer on, later than the limit here, for a start where none of this runs', () => {
      const config = readFileSync(resolve(ROOT, 'capacitor.config.ts'), 'utf8');
      const splash = /SplashScreen:\s*\{([\s\S]*?)\n\s*\}/.exec(config)?.[1] ?? '';

      expect(splash).toMatch(/launchAutoHide:\s*true\b/);
      expect(Number(/launchShowDuration:\s*(\d+)/.exec(splash)?.[1])).toBeGreaterThan(LAUNCH_SCREEN_LIMIT_MS);
    });

    it('gives the theme the app runs in the launch picture, which is where Android 11 and older read it from', () => {
      const styles = readFileSync(resolve(ROOT, 'android/app/src/main/res/values/styles.xml'), 'utf8');
      const theme = /<style name="AppTheme\.NoActionBar"[^>]*>([\s\S]*?)<\/style>/.exec(styles)?.[1] ?? '';

      for (const item of ['windowSplashScreenBackground', 'windowSplashScreenAnimatedIcon', 'splashScreenIconSize']) {
        expect(theme, item).toContain(`<item name="${item}"`);
      }
    });
  });
});
