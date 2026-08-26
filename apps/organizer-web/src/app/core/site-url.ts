import { InjectionToken, inject } from '@angular/core';
import { DOCUMENT } from '@angular/common';

/**
 * Where the public site lives.
 *
 * The console links out to it constantly — "view the page a buyer sees" is
 * how an organizer checks their own work, and the same URL is the one they
 * paste into a post. It is a different host from this one, so the link cannot
 * be relative: the console serves no event pages.
 *
 * Resolved at runtime rather than compiled in, matching how the site finds the
 * API: one bundle then serves staging and production, and a staging build
 * promoted to production cannot send organizers to a staging site — or, worse,
 * send their buyers there.
 *
 * Browser only. Unlike the public site this console is not server-rendered, so
 * there is no server half to read an environment variable in.
 */
export const SITE_URL = new InjectionToken<string>('SITE_URL', {
  providedIn: 'root',
  factory: () => {
    const meta = inject(DOCUMENT).querySelector<HTMLMetaElement>('meta[name="site-url"]');

    return meta?.content ?? 'http://localhost:4320';
  },
});
