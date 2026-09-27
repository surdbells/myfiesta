import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, RouteReuseStrategy, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const plugin = vi.hoisted(() => ({
  native: true,
  listeners: new Map<string, (event: { url: string }) => void>(),
  launchUrl: null as string | null,
  browsed: [] as string[],
}));

vi.mock('@capacitor/browser', () => ({
  Browser: {
    open: async ({ url }: { url: string }) => {
      plugin.browsed.push(url);
    },
  },
}));

vi.mock('@capacitor/app', () => ({
  App: {
    addListener: async (name: string, callback: (event: { url: string }) => void) => {
      plugin.listeners.set(name, callback);
      return { remove: () => undefined };
    },
    getLaunchUrl: async () => (plugin.launchUrl ? { url: plugin.launchUrl } : undefined),
  },
}));

vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => plugin.native, getPlatform: () => 'android' },
  registerPlugin: () => ({}),
  WebPlugin: class {},
}));

import { DeepLinks, LinkedScreenReuse, appRouteFor, onSite } from './deep-links';
import { Discover } from './discovery';
import { SessionStore } from './session';

const SITE = 'https://myfiesta.ca';

/**
 * Which links the app opens, and where.
 *
 * The native side claims a set of paths on the site (the apple-app-site-
 * association file and the Android intent filters); this is the other half,
 * and the half that decides. Android cannot express "one path segment that is
 * not help" in a manifest, so some links arrive here that the app has no
 * screen for — and the answer to those has to be the site's page, not a
 * screen saying an event called "help" does not exist.
 */
describe('appRouteFor', () => {
  it('opens an event page on the event screen', () => {
    expect(appRouteFor('https://myfiesta.ca/afro-fest-2026', SITE)).toBe('/e/afro-fest-2026');
  });

  it('opens an organizer page on the organizer screen', () => {
    expect(appRouteFor('https://myfiesta.ca/o/lagos-nights', SITE)).toBe('/o/lagos-nights');
  });

  it('opens the listing on Browse', () => {
    expect(appRouteFor('https://myfiesta.ca/events', SITE)).toBe('/browse');
    expect(appRouteFor('https://myfiesta.ca/events?city=Toronto', SITE)).toBe('/browse');
  });

  it("opens a promoter's link on the event screen with its ref, which the ticket page is then given", () => {
    expect(appRouteFor('https://myfiesta.ca/afro-fest?ref=dj-kay', SITE)).toBe('/e/afro-fest?ref=dj-kay');
    // The ref alone: the tracking tag beside it is the site's business.
    expect(appRouteFor('https://myfiesta.ca/afro-fest?utm_source=ig&ref=spring-mail', SITE)).toBe('/e/afro-fest?ref=spring-mail');
    // Carried as it came, still encoded: it is a value, never a path or a tag.
    expect(appRouteFor('https://myfiesta.ca/afro-fest?ref=%3Cscript%3E', SITE)).toBe('/e/afro-fest?ref=%3Cscript%3E');
    expect(appRouteFor('https://myfiesta.ca/afro-fest?ref=a%26b%3Dc', SITE)).toBe('/e/afro-fest?ref=a%26b%3Dc');
  });

  it('drops any other query, and a ref with nothing in it', () => {
    expect(appRouteFor('https://myfiesta.ca/afro-fest?utm_source=ig', SITE)).toBe('/e/afro-fest');
    expect(appRouteFor('https://myfiesta.ca/afro-fest?ref=', SITE)).toBe('/e/afro-fest');
  });

  it('takes the www. address and a trailing slash as the same page', () => {
    expect(appRouteFor('https://www.myfiesta.ca/afro-fest/', SITE)).toBe('/e/afro-fest');
    expect(appRouteFor('https://myfiesta.ca/afro-fest', 'https://www.myfiesta.ca')).toBe('/e/afro-fest');
  });

  it("leaves the site's own pages to the site", () => {
    for (const page of ['help', 'terms', 'privacy', 'contact', 'refunds', 'tickets', 'register', 'sign-in', 'login', 'order', 'o', 'embed']) {
      expect(appRouteFor(`https://myfiesta.ca/${page}`, SITE), page).toBeNull();
    }
  });

  it("leaves a buyer's tickets and orders to the site, where a guest can see them", () => {
    expect(appRouteFor('https://myfiesta.ca/tickets/Qm9vbXRva2Vu', SITE)).toBeNull();
    expect(appRouteFor('https://myfiesta.ca/order/MF-7Q2K9', SITE)).toBeNull();
  });

  it('leaves the checkout to the site — the app sends people there on purpose', () => {
    expect(appRouteFor('https://myfiesta.ca/afro-fest/tickets', SITE)).toBeNull();
    expect(appRouteFor('https://myfiesta.ca/afro-fest/checkout', SITE)).toBeNull();
    expect(appRouteFor('https://myfiesta.ca/embed/afro-fest', SITE)).toBeNull();
  });

  it('opens nothing for the front page, files, or anything that is not a slug', () => {
    expect(appRouteFor('https://myfiesta.ca/', SITE)).toBeNull();
    expect(appRouteFor('https://myfiesta.ca/robots.txt', SITE)).toBeNull();
    expect(appRouteFor('https://myfiesta.ca/Afro-Fest', SITE)).toBeNull();
    expect(appRouteFor('https://myfiesta.ca/o/lagos-nights/past', SITE)).toBeNull();
  });

  it('opens nothing on another host, however the path looks', () => {
    expect(appRouteFor('https://myfiesta.ca.evil.test/afro-fest', SITE)).toBeNull();
    expect(appRouteFor('https://console.myfiesta.ca/afro-fest', SITE)).toBeNull();
    expect(appRouteFor('https://myfiesta.ca:8443/afro-fest', SITE)).toBeNull();
  });

  it('opens nothing over plain http, or from another scheme, or from nonsense', () => {
    expect(appRouteFor('http://myfiesta.ca/afro-fest', SITE)).toBeNull();
    expect(appRouteFor('myfiesta.os.ca://afro-fest', SITE)).toBeNull();
    expect(appRouteFor('not a url', SITE)).toBeNull();
  });

  it('follows the site the app is pointed at, including a development one on http', () => {
    expect(appRouteFor('http://localhost:4320/afro-fest', 'http://localhost:4320')).toBe('/e/afro-fest');
    expect(appRouteFor('https://myfiesta.ca/afro-fest', 'https://staging.myfiesta.ca')).toBeNull();
  });
});

describe('onSite', () => {
  it("knows the site's own addresses, www. or not", () => {
    expect(onSite('https://myfiesta.ca/privacy', SITE)?.pathname).toBe('/privacy');
    expect(onSite('https://www.myfiesta.ca/help', SITE)?.pathname).toBe('/help');
  });

  it('is not fooled by a lookalike host, another port, plain http or another scheme', () => {
    expect(onSite('https://myfiesta.ca.evil.test/privacy', SITE)).toBeNull();
    expect(onSite('https://console.myfiesta.ca/door-pass/abc', SITE)).toBeNull();
    expect(onSite('https://myfiesta.ca:8443/privacy', SITE)).toBeNull();
    expect(onSite('http://myfiesta.ca/privacy', SITE)).toBeNull();
    expect(onSite('javascript:alert(1)', SITE)).toBeNull();
    expect(onSite('not a url', SITE)).toBeNull();
  });
});

@Component({ template: '' })
class Blank {}

describe('DeepLinks', () => {
  const locked = signal(false);
  let router: Router;
  let links: DeepLinks;

  beforeEach(() => {
    plugin.native = true;
    plugin.listeners.clear();
    plugin.launchUrl = null;
    plugin.browsed = [];
    locked.set(false);
    vi.restoreAllMocks();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', component: Blank },
          { path: 'e/:slug', component: Blank },
          { path: 'o/:slug', component: Blank },
          { path: 'browse', component: Blank },
          { path: 'door', component: Blank },
        ]),
        { provide: Discover, useValue: { siteBase: () => SITE } },
        { provide: SessionStore, useValue: { locked } },
      ],
    });

    router = TestBed.inject(Router);
    links = TestBed.inject(DeepLinks);
  });

  it('opens a link that arrives while the app is running', async () => {
    links.listen();
    await Promise.resolve();

    plugin.listeners.get('appUrlOpen')!({ url: 'https://myfiesta.ca/afro-fest' });
    await vi.waitFor(() => expect(router.url).toBe('/e/afro-fest'));
  });

  it('opens the link a cold start was launched with', async () => {
    plugin.launchUrl = 'https://myfiesta.ca/o/lagos-nights';

    links.listen();

    await vi.waitFor(() => expect(router.url).toBe('/o/lagos-nights'));
  });

  it('opens the privacy page in the browser, so the deletion link in the Play listing reaches the form', async () => {
    await router.navigateByUrl('/browse');

    expect(links.open('https://myfiesta.ca/privacy')).toBe(false);
    expect(plugin.browsed).toEqual(['https://myfiesta.ca/privacy']);
    expect(router.url).toBe('/browse');
  });

  it("opens the site's other pages in the browser too, as tapped", async () => {
    await router.navigateByUrl('/browse');

    for (const link of ['https://www.myfiesta.ca/help', 'https://myfiesta.ca/refunds', 'https://myfiesta.ca/tickets/Qm9vbXRva2Vu']) {
      links.open(link);
    }

    expect(plugin.browsed).toEqual(['https://www.myfiesta.ca/help', 'https://myfiesta.ca/refunds', 'https://myfiesta.ca/tickets/Qm9vbXRva2Vu']);
    expect(router.url).toBe('/browse');
  });

  it("opens a promoter's link on the event screen, keeping its ref", async () => {
    await router.navigateByUrl('/browse');

    expect(links.open('https://myfiesta.ca/afro-fest?ref=dj-kay')).toBe(true);
    await vi.waitFor(() => expect(router.url).toBe('/e/afro-fest?ref=dj-kay'));
    expect(router.parseUrl(router.url).queryParams).toEqual({ ref: 'dj-kay' });
    expect(plugin.browsed).toEqual([]);
  });

  it('does not hand the same page over again when Android bounces it straight back', () => {
    const now = vi.spyOn(Date, 'now').mockReturnValue(1_000_000);

    links.open('https://myfiesta.ca/privacy');
    now.mockReturnValue(1_000_400);
    links.open('https://myfiesta.ca/privacy');

    expect(plugin.browsed).toEqual(['https://myfiesta.ca/privacy']);

    // Somebody tapping it again later is a new request.
    now.mockReturnValue(1_010_000);
    links.open('https://myfiesta.ca/privacy');

    expect(plugin.browsed).toEqual(['https://myfiesta.ca/privacy', 'https://myfiesta.ca/privacy']);
  });

  it('opens nothing, here or in a browser, for a link to another site', async () => {
    await router.navigateByUrl('/browse');

    expect(links.open('https://myfiesta.ca.evil.test/privacy')).toBe(false);
    expect(links.open('https://console.myfiesta.ca/door-pass/abc')).toBe(false);
    expect(plugin.browsed).toEqual([]);
    expect(router.url).toBe('/browse');
  });

  it('opens nothing on a door pass: that phone has one screen', async () => {
    await router.navigateByUrl('/door');
    locked.set(true);

    expect(links.open('https://myfiesta.ca/afro-fest')).toBe(false);
    expect(links.open('https://myfiesta.ca/privacy')).toBe(false);
    expect(plugin.browsed).toEqual([]);
    expect(router.url).toBe('/door');
  });

  it('does not listen in a browser, where the site is simply the site', async () => {
    plugin.native = false;

    links.listen();
    await Promise.resolve();

    expect(plugin.listeners.size).toBe(0);
  });
});

/**
 * The event screen loads once, when it is made. Reusing it for a different
 * event — which is what the router does by default, and what a link arriving
 * over an open event asks for — would change the address and keep showing the
 * first night.
 */
describe('LinkedScreenReuse', () => {
  let harness: RouterTestingHarness;
  let made: number;

  @Component({ template: '' })
  class Counted {
    constructor() {
      made++;
    }
  }

  beforeEach(async () => {
    made = 0;

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'e/:slug', component: Counted },
          { path: 'manage/events/:id', component: Counted },
        ]),
        { provide: RouteReuseStrategy, useClass: LinkedScreenReuse },
      ],
    });

    harness = await RouterTestingHarness.create();
  });

  it('makes a new event screen for a different event', async () => {
    await harness.navigateByUrl('/e/afro-fest');
    await harness.navigateByUrl('/e/amapiano-sunday');

    expect(made).toBe(2);
  });

  it('keeps the screen when only the query changes', async () => {
    await harness.navigateByUrl('/e/afro-fest');
    await harness.navigateByUrl('/e/afro-fest?ref=dj-kay');

    expect(made).toBe(1);
  });

  it("leaves every other route to the router's own rule", async () => {
    await harness.navigateByUrl('/manage/events/1');
    await harness.navigateByUrl('/manage/events/2');

    expect(made).toBe(1);
  });
});
