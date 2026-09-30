import { Component, computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const phone = vi.hoisted(() => ({
  native: true,
  launchUrl: null as string | null,
  stored: new Map<string, string>(),
}));

vi.mock('@capacitor/preferences', () => ({
  Preferences: {
    get: async ({ key }: { key: string }) => ({ value: phone.stored.get(key) ?? null }),
    set: async ({ key, value }: { key: string; value: string }) => void phone.stored.set(key, value),
    remove: async ({ key }: { key: string }) => void phone.stored.delete(key),
  },
}));

vi.mock('@capacitor/app', () => ({
  App: {
    addListener: async () => ({ remove: () => undefined }),
    getLaunchUrl: async () => (phone.launchUrl ? { url: phone.launchUrl } : undefined),
  },
}));

vi.mock('@capacitor/haptics', () => ({
  Haptics: { impact: async () => undefined },
  ImpactStyle: { Light: 'LIGHT' },
}));

vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => phone.native, getPlatform: () => 'android', isPluginAvailable: () => false },
  registerPlugin: () => ({}),
  WebPlugin: class {},
}));

import { DeepLinks } from '../../core/deep-links';
import { Discover } from '../../core/discovery';
import { INTRO_PATH, Intro, introFirst } from '../../core/intro';
import { Navigation } from '../../core/navigation';
import { SessionStore } from '../../core/session';
import { routes } from '../../app.routes';
import { INTRO_PAGES, IntroScreen } from './intro';

@Component({ template: '' })
class Elsewhere {}

const SEEN = 'myfiesta.intro';

/**
 * The first-run introduction.
 *
 * What it promises: a new phone opens on it once; every way out of it —
 * Skip, Get started, I run events — is remembered, so it never comes back
 * unasked; a phone already signed in when it arrives in an update is not a
 * new phone; Settings, and signing in for somebody without an account,
 * bring it back on request; and somebody who arrived by a link, or is
 * working a door, goes straight where they were going and meets it the next
 * time they open the app themselves.
 */
describe('the introduction', () => {
  const scope = signal<'attendee' | 'organizer' | 'door' | null>(null);

  beforeEach(() => {
    phone.native = true;
    phone.launchUrl = null;
    phone.stored.clear();
    scope.set(null);
    launch();
  });

  afterEach(() => vi.restoreAllMocks());

  /** A fresh app: what a relaunch of the phone app starts from. */
  function launch(): void {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', canActivate: [introFirst], component: Elsewhere },
          { path: 'welcome', component: IntroScreen },
          { path: 'e/:slug', component: Elsewhere },
          { path: 'tickets/:id', component: Elsewhere },
          { path: 'door-pass/:secret', component: Elsewhere },
          { path: 'door', component: Elsewhere },
          { path: 'settings', component: Elsewhere },
          { path: 'sign-in', component: Elsewhere },
          { path: 'manage', component: Elsewhere },
        ]),
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.ca' } },
        {
          provide: SessionStore,
          useValue: {
            scope,
            locked: computed(() => scope() === 'door'),
            canSeeSales: computed(() => scope() === 'organizer'),
            signedIn: computed(() => scope() !== null),
          },
        },
      ],
    });
  }

  /** The app starting: app.config's initializer, then its first screen. */
  async function open(url = '/'): Promise<{ harness: RouterTestingHarness; router: Router }> {
    // Listening before the first navigation, as it is in the app, so it knows
    // what is underneath each screen.
    TestBed.inject(Navigation);
    await TestBed.inject(Intro).restore();

    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(url);

    return { harness, router: TestBed.inject(Router) };
  }

  function screen(harness: RouterTestingHarness): IntroScreen {
    return harness.routeDebugElement!.componentInstance as IntroScreen;
  }

  function button(harness: RouterTestingHarness, words: string): HTMLButtonElement {
    const found = [...(harness.routeNativeElement as HTMLElement).querySelectorAll('button')].find(
      (b) => b.textContent?.trim() === words,
    );

    if (!found) throw new Error(`No "${words}" button on screen`);

    return found as HTMLButtonElement;
  }

  describe('on a first launch', () => {
    it('opens on the introduction instead of What’s on', async () => {
      const { harness, router } = await open();

      expect(router.url).toBe(INTRO_PATH);
      expect(harness.routeNativeElement?.querySelector('h1')?.textContent).toContain('myFiesta');
      expect(harness.routeNativeElement?.querySelector('h2')?.textContent).toBe('Nights out, sorted');
    });

    it('speaks to both kinds of people who open the app', () => {
      const words = INTRO_PAGES.map((page) => `${page.title} ${page.body}`).join(' ');

      expect(INTRO_PAGES.length).toBeGreaterThanOrEqual(3);
      expect(INTRO_PAGES.length).toBeLessThanOrEqual(4);

      // Going out: finding, buying, and getting in with no signal.
      expect(words).toMatch(/near you/);
      expect(words).toMatch(/few taps/);
      expect(words).toMatch(/door/);
      expect(words).toMatch(/no signal/);

      // Running events: selling, the door, sales, and being paid.
      expect(words).toMatch(/Sell tickets/);
      expect(words).toMatch(/scan them at the door/);
      expect(words).toMatch(/get paid/);
    });

    it('is not a first launch for an account already signed in, updating to the version that has it', async () => {
      scope.set('organizer');

      const { router } = await open();

      expect(router.url).toBe('/');
      // Remembered, so signing out afterwards does not bring it up either.
      expect(phone.stored.get(SEEN)).toBe('seen');
    });
  });

  describe('every way out is remembered', () => {
    it('Skip goes to What’s on, and the next launch opens there too', async () => {
      const { harness, router } = await open();

      button(harness, 'Skip').click();
      await vi.waitFor(() => expect(router.url).toBe('/'));

      expect(phone.stored.get(SEEN)).toBe('seen');

      launch();
      const again = await open();

      expect(again.router.url).toBe('/');
    });

    it('Get started, at the end, goes to What’s on', async () => {
      const { harness, router } = await open();
      const intro = screen(harness);

      for (let page = 1; page < INTRO_PAGES.length; page++) button(harness, 'Next').click();
      harness.detectChanges();

      expect(intro.current()).toBe(INTRO_PAGES.length - 1);

      button(harness, 'Get started').click();
      await vi.waitFor(() => expect(router.url).toBe('/'));

      expect(phone.stored.get(SEEN)).toBe('seen');
    });

    it('"I run events" signs in to the organizer screens, and comes back to them', async () => {
      const { harness, router } = await open();

      button(harness, 'I run events').click();
      await vi.waitFor(() => expect(router.url).toBe('/sign-in?next=%2Fmanage'));

      expect(phone.stored.get(SEEN)).toBe('seen');
    });

    it('"I run events" goes straight to them for an organizer already signed in', async () => {
      scope.set('organizer');
      // Asked for, from Settings: a phone signed in at launch never opens on it.
      const { harness, router } = await open(INTRO_PATH);

      button(harness, 'I run events').click();

      await vi.waitFor(() => expect(router.url).toBe('/manage'));
    });

    it('back on the first page is a skip', async () => {
      const { harness, router } = await open();
      const intro = screen(harness);
      const nav = TestBed.inject(Navigation);

      intro.go(2);
      nav.goBack();
      expect(intro.current()).toBe(1);

      nav.goBack();
      nav.goBack();

      await vi.waitFor(() => expect(router.url).toBe('/'));
      expect(phone.stored.get(SEEN)).toBe('seen');
    });
  });

  describe('moving through it', () => {
    it('moves by Next, the dots and the arrow keys, and says where it has moved to', async () => {
      const { harness } = await open();
      const intro = screen(harness);
      const host = harness.routeNativeElement as HTMLElement;
      const key = (name: string) => host.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));

      // Nothing said on arrival: the screen itself is what is read first.
      expect(intro.announcement()).toBe('');

      button(harness, 'Next').click();
      expect(intro.current()).toBe(1);
      expect(intro.announcement()).toBe(`Page 2 of ${INTRO_PAGES.length}: ${INTRO_PAGES[1].title}`);

      key('ArrowRight');
      expect(intro.current()).toBe(2);

      key('End');
      expect(intro.current()).toBe(INTRO_PAGES.length - 1);

      key('ArrowRight');
      expect(intro.current()).toBe(INTRO_PAGES.length - 1);

      key('Home');
      expect(intro.current()).toBe(0);

      const dots = host.querySelectorAll<HTMLButtonElement>('.dot');
      expect(dots.length).toBe(INTRO_PAGES.length);
      expect(dots[2].getAttribute('aria-label')).toBe(`Page 3 of ${INTRO_PAGES.length}: ${INTRO_PAGES[2].title}`);

      dots[2].click();
      harness.detectChanges();

      expect(intro.current()).toBe(2);
      expect(dots[2].getAttribute('aria-current')).toBe('step');
      expect(host.querySelector('[aria-live="polite"]')?.textContent).toContain(INTRO_PAGES[2].title);
    });

    it('shows a screen reader only the page on screen', async () => {
      const { harness } = await open();
      const host = harness.routeNativeElement as HTMLElement;

      button(harness, 'Next').click();
      harness.detectChanges();

      const hidden = [...host.querySelectorAll('.page')].map((page) => page.getAttribute('aria-hidden'));

      expect(hidden).toEqual(INTRO_PAGES.map((_, i) => (i === 1 ? null : 'true')));
    });
  });

  describe('asked for again', () => {
    it('opens again when asked, over Settings, and Skip goes back there', async () => {
      phone.stored.set(SEEN, 'seen');
      const { harness, router } = await open('/settings');
      const back = vi.spyOn(history, 'back').mockImplementation(() => undefined);

      await harness.navigateByUrl(INTRO_PATH);
      expect(router.url).toBe(INTRO_PATH);

      button(harness, 'Skip').click();

      await vi.waitFor(() => expect(back).toHaveBeenCalled());
    });

    // Settings needs an account, and most people here buy without one: the
    // You tab takes them to signing in, whose link is the way back in
    // (auth/sign-in.spec.ts).
    it('opens for somebody signed out, over signing in, and Skip goes back there', async () => {
      phone.stored.set(SEEN, 'seen');
      const { harness, router } = await open('/sign-in');
      const back = vi.spyOn(history, 'back').mockImplementation(() => undefined);

      await harness.navigateByUrl(INTRO_PATH);
      expect(router.url).toBe(INTRO_PATH);

      button(harness, 'Skip').click();

      await vi.waitFor(() => expect(back).toHaveBeenCalled());
    });
  });

  describe('never in front of somebody on their way somewhere', () => {
    it('lets a launch from a link through, and shows the introduction the next time', async () => {
      phone.launchUrl = 'https://myfiesta.ca/afro-fest';

      const { router } = await open();

      expect(router.url).toBe('/');
      expect(phone.stored.has(SEEN)).toBe(false);

      launch();
      phone.launchUrl = null;
      const again = await open();

      expect(again.router.url).toBe(INTRO_PATH);
    });

    it('gives way to a link that arrives while it is on screen, without leaving itself underneath', async () => {
      const { router } = await open();
      const navigate = vi.spyOn(router, 'navigateByUrl');

      expect(TestBed.inject(DeepLinks).open('https://myfiesta.ca/afro-fest')).toBe(true);

      await vi.waitFor(() => expect(router.url).toBe('/e/afro-fest'));
      expect(navigate).toHaveBeenCalledWith('/e/afro-fest', { replaceUrl: true });

      // Not now, and not remembered as seen either.
      expect(TestBed.inject(Intro).due()).toBe(false);
      expect(phone.stored.has(SEEN)).toBe(false);

      // What's on, from the tab bar, is What's on.
      await router.navigateByUrl('/');
      expect(router.url).toBe('/');
    });

    it('lets a ticket and the door flow through, and shows it the next time', async () => {
      for (const url of ['/tickets/ticket-1', '/door-pass/tonight', '/e/afro-fest']) {
        launch();
        const { router } = await open(url);

        expect(router.url).toBe(url);

        // The tab bar, afterwards: the app has already shown something.
        await router.navigateByUrl('/');
        expect(router.url).toBe('/');
      }

      expect(phone.stored.has(SEEN)).toBe(false);

      launch();
      expect((await open()).router.url).toBe(INTRO_PATH);
    });

    it('is never shown on a door pass, and waits for the pass to end rather than being remembered', async () => {
      scope.set('door');

      const { router } = await open();

      expect(router.url).toBe('/');
      expect(phone.stored.has(SEEN)).toBe(false);
    });
  });

  it('is wired into the app: What’s on asks first, and the introduction has its route, open to anybody', () => {
    const home = routes.find((route) => route.path === '');
    const intro = routes.find((route) => '/' + route.path === INTRO_PATH);

    expect(home?.canActivate).toContain(introFirst);
    expect(intro?.loadComponent).toBeDefined();
    expect(intro?.canActivate).toBeUndefined();
  });
});
