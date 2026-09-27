import { Injectable, NgZone, inject } from '@angular/core';
import { ActivatedRouteSnapshot, BaseRouteReuseStrategy, Router } from '@angular/router';
import { App as CapacitorApp } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { Capacitor } from '@capacitor/core';
import { Discover } from './discovery';
import { SessionStore } from './session';

/**
 * The site's own pages at its root, which are not events.
 *
 * Event pages live at myfiesta.ca/{slug}, beside these. The API refuses to
 * give an event any of these slugs, so a link to one of them is the site's
 * page and never an event: opening `/help` as an event would be a screen that
 * says "event not found" to somebody who asked for help. Keep in step with
 * `Event::RESERVED_SLUGS` in apps/api and the top-level routes in apps/web.
 */
const SITE_PAGES = new Set([
  'events',
  'help',
  'terms',
  'privacy',
  'contact',
  'refunds',
  'order',
  'tickets',
  'register',
  'sign-in',
  'login',
  'o',
  'embed',
]);

/**
 * What a slug looks like: lowercase letters, digits and hyphens. The API makes
 * them that way and so did the import from the previous platform, so anything
 * else — a file name with a dot, a path with capitals — is not an event.
 */
const SLUG = /^[a-z0-9][a-z0-9-]*$/;

/**
 * How long a link handed to the browser counts as just handed.
 *
 * Long enough for Android to bounce it straight back (see DeepLinks.toBrowser)
 * and for iOS to deliver a cold start's link twice; short enough that somebody
 * who closes the page, goes back to the chat and taps the link again gets it.
 */
const HANDED_ON_MS = 3000;

/**
 * Where in the app a link to the public site belongs, or null when the app
 * has no screen for it.
 *
 * - `/{slug}` is an event, and opens `/e/{slug}`. A promoter's link,
 *   `/{slug}?ref=…`, opens `/e/{slug}?ref=…`: the ref is what credits the
 *   sale, unlocks a presale tier and gives the promoter's discount, and the
 *   event screen passes it on to the site's ticket page when somebody buys.
 * - `/o/{slug}` is an organizer, and opens the same address here.
 * - `/events`, the listing, opens Browse.
 *
 * Everything else is the site's: a buyer's tickets and orders (the address is
 * the credential, and the site is where a guest with no account sees them),
 * the checkout steps, the embeds, and the help, privacy and refund pages.
 * Those are null, and DeepLinks hands the ones on the site's host to the
 * browser rather than guessing.
 *
 * Only links on the site's own host count — with or without `www.` — because
 * the operating system hands the app whatever it was asked to, and a URL for
 * some other site is not a route here however its path is shaped.
 */
export function appRouteFor(link: string, siteBase: string): string | null {
  const url = onSite(link, siteBase);

  if (!url) return null;

  // Tolerates a trailing slash; a path with anything more is not ours.
  const parts = url.pathname.split('/').filter(Boolean);

  if (parts.length === 1 && parts[0] === 'events') return '/browse';

  if (parts.length === 2 && parts[0] === 'o' && SLUG.test(parts[1])) return `/o/${parts[1]}`;

  if (parts.length === 1 && SLUG.test(parts[0]) && !SITE_PAGES.has(parts[0])) {
    // The ref and nothing else: a tracking tag means nothing to the app, and
    // a ref left behind here is a promoter who is not paid for the sale.
    const ref = url.searchParams.get('ref');

    return ref ? `/e/${parts[0]}?ref=${encodeURIComponent(ref)}` : `/e/${parts[0]}`;
  }

  return null;
}

/**
 * The link, parsed, when it is an address on the public site; null for any
 * other host, scheme or string.
 *
 * https always; plain http only where the site itself is (development).
 */
export function onSite(link: string, siteBase: string): URL | null {
  let url: URL;
  let site: URL;

  try {
    url = new URL(link);
    site = new URL(siteBase);
  } catch {
    return null;
  }

  if (url.protocol !== 'https:' && url.protocol !== site.protocol) return null;
  if (bare(url.hostname) !== bare(site.hostname) || url.port !== site.port) return null;

  return url;
}

function bare(hostname: string): string {
  return hostname.toLowerCase().replace(/^www\./, '');
}

/**
 * Links to the public site, opened in the app.
 *
 * With the app installed, a tap on myfiesta.ca/{slug} in a group chat,
 * Instagram or an email comes here instead of the browser — Android App Links
 * and iOS Universal Links, set up in the native projects and verified by the
 * files the site serves under /.well-known/. docs/STORE.md has the whole
 * arrangement.
 *
 * The checkout does not come back this way. The app opens it in the system
 * browser and it finishes there, on the site's order page, which the buyer
 * closes; there is no custom scheme for a payment page to return to, and the
 * site's checkout and order pages are deliberately not links the app claims.
 *
 * Some of the site's own pages arrive anyway. On Android 12 and up the app
 * claims every one-word address, because an event's is one word, and that
 * takes in /privacy, /help and /refunds too. Those go on to the browser.
 * Staying put would leave somebody who tapped the account-deletion link in
 * the Play listing looking at whatever screen the app was last on.
 */
@Injectable({ providedIn: 'root' })
export class DeepLinks {
  private readonly router = inject(Router);
  private readonly zone = inject(NgZone);
  private readonly discover = inject(Discover);
  private readonly session = inject(SessionStore);

  /** The last link handed to the browser, and when. */
  private handedOn: { link: string; at: number } | null = null;

  /**
   * Start listening. Called once the session is restored, so a door pass is
   * already known to be one when the first link arrives.
   */
  listen(): void {
    if (!Capacitor.isNativePlatform()) return;

    void CapacitorApp.addListener('appUrlOpen', ({ url }) => this.open(url));

    // A cold start from a link: iOS delivers it as the event above, Android
    // only through this. Opening the same screen twice is a no-op, and the
    // same page is not handed to the browser twice, so asking on both
    // platforms is harmless.
    void CapacitorApp.getLaunchUrl()
      .then((launch) => {
        if (launch?.url) this.open(launch.url);
      })
      .catch(() => undefined);
  }

  /**
   * Opens a link on its screen, if the app has one, or else in the browser
   * when it is one of the site's pages. True when the app opened a screen.
   */
  open(link: string): boolean {
    // A door pass has one screen and no way out but ending the shift; a link
    // tapped on a lent phone must not be one, in the app or in a browser.
    if (this.session.locked()) return false;

    const site = this.discover.siteBase();
    const route = appRouteFor(link, site);

    if (!route) {
      // Only the site's own pages. Anything else never came through the
      // links the app claims, and the app is not a way to open it.
      if (onSite(link, site)) this.toBrowser(link);

      return false;
    }

    // Plugin events arrive outside Angular's zone.
    this.zone.run(() => void this.router.navigateByUrl(route));

    return true;
  }

  /**
   * One of the site's pages, in the system browser, once.
   *
   * On Android the browser is normally named outright — Custom Tabs targets
   * the browser's package — so the link cannot come back here. Before the
   * browser's tab service has connected, or on a phone whose browser has no
   * Custom Tabs, it goes out as an ordinary link instead, and Android, which
   * has verified this app for the site, hands it straight back. The same link
   * returning within a few seconds is that bounce, and the app stops there
   * rather than open it again and again.
   */
  private toBrowser(link: string): void {
    const now = Date.now();

    if (this.handedOn?.link === link && now - this.handedOn.at < HANDED_ON_MS) return;

    this.handedOn = { link, at: now };

    void Browser.open({ url: link }).catch(() => undefined);
  }
}

/**
 * One event's screen opened over another event's is a new screen.
 *
 * The router's default keeps the component when only a parameter changes, and
 * the event and organizer screens load once, when they are made. Nothing in
 * the app goes from one event straight to another, so that never showed — but
 * a link does exactly that: somebody reading one event taps a link to the
 * next in a chat, and without this the screen changes address and goes on
 * showing the first.
 *
 * Only those two routes. Everywhere else keeps the router's behaviour.
 */
export class LinkedScreenReuse extends BaseRouteReuseStrategy {
  private static readonly ONE_THING = new Set(['e/:slug', 'o/:slug']);

  override shouldReuseRoute(future: ActivatedRouteSnapshot, current: ActivatedRouteSnapshot): boolean {
    if (future.routeConfig !== current.routeConfig) return false;

    if (LinkedScreenReuse.ONE_THING.has(future.routeConfig?.path ?? '')) {
      return future.paramMap.get('slug') === current.paramMap.get('slug');
    }

    return true;
  }
}
