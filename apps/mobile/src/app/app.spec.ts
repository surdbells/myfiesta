import { TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { Router, provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@capacitor/app', () => ({
  App: { addListener: async () => ({ remove: () => undefined }) },
}));

/*
 * The plugin modules register themselves through this one as they load, so the
 * stub has to answer registerPlugin as well as Capacitor — replacing it with
 * Capacitor alone means nothing in the import graph loads at all.
 */
vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => false, getPlatform: () => 'web' },
  registerPlugin: () => ({}),
  WebPlugin: class {},
}));

import { App as Shell } from './app';
import { routes } from './app.routes';
import { SessionStore } from './core/session';

/**
 * The shell decides two things, and both rot quietly.
 *
 * The tab bar's links have to be routes that exist — a tab pointing at nothing
 * is a dead end nobody notices until somebody taps it. And the rule for hiding
 * the bar is a pattern listing screens by name, which stops matching the moment
 * a screen is renamed or a new one is added beside it.
 */
describe('the shell', () => {
  let shell: Shell;
  let router: Router;

  // A signal, because the shell reads it inside computeds — a plain function
  // would be read once and never again, and every later change would be
  // invisible to the thing under test rather than to the app.
  const scope = signal<'attendee' | 'organizer' | 'door' | null>('attendee');

  beforeEach(() => {
    scope.set('attendee');

    TestBed.configureTestingModule({
      providers: [
        provideRouter(routes),
        {
          provide: SessionStore,
          useValue: {
            locked: computed(() => scope() === 'door'),
            canSeeSales: computed(() => scope() === 'organizer'),
            scope,
            signedIn: computed(() => scope() !== null),
            session: computed(() => (scope() ? { scope: scope(), token: 't' } : null)),
          },
        },
      ],
    });

    router = TestBed.inject(Router);
    shell = TestBed.createComponent(Shell).componentInstance;
  });

  /** What the router would do with a link, without rendering the screen. */
  const resolves = (link: string) => router.parseUrl(link) && router.config.some((route) => {
    const path = route.path ?? '';

    return '/' + path === link || (path !== '' && link.startsWith('/' + path.split('/')[0]));
  });

  it('points every tab at a route that exists', () => {
    scope.set('organizer');

    for (const tab of shell.tabs()) {
      expect(resolves(tab.link), `${tab.label} points at ${tab.link}`).toBe(true);
    }
  });

  it('shows the money tab only to somebody who may see money', () => {
    scope.set('attendee');
    expect(shell.tabs().map((tab) => tab.link)).not.toContain('/events');

    scope.set('organizer');
    expect(shell.tabs().map((tab) => tab.link)).toContain('/events');
  });

  describe('the tab bar', () => {
    async function at(url: string) {
      await router.navigateByUrl(url);

      return shell.showTabs();
    }

    it('stays out of the way while somebody is getting into an account', async () => {
      // Signing in, signing up and asking for a reset link are one flow. A tab
      // bar under them offers to leave halfway through.
      expect(await at('/sign-in')).toBe(false);
      expect(await at('/join')).toBe(false);
      expect(await at('/forgotten-password')).toBe(false);
    });

    it('stays out of the way of a door and a ticket being held up', async () => {
      // An organizer working their own door: an attendee asking for /door is
      // sent home by the route guard before the bar is ever a question.
      scope.set('organizer');

      expect(await at('/door')).toBe(false);
      expect(await at('/tickets/some-ticket-id')).toBe(false);
      expect(await at('/ui')).toBe(false);
    });

    it('is there for the screens somebody moves between', async () => {
      expect(await at('/')).toBe(true);
      expect(await at('/browse')).toBe(true);
      expect(await at('/e/afro-fest')).toBe(true);
      expect(await at('/tickets')).toBe(true);
      expect(await at('/settings')).toBe(true);
    });

    it('is gone entirely on a phone that is a door pass', async () => {
      scope.set('door');

      // That phone has one screen. Everything else on it belongs to whoever
      // lent it out for the night.
      expect(await at('/')).toBe(false);
      expect(await at('/settings')).toBe(false);
    });
  });
});
