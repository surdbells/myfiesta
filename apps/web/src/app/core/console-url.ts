import { InjectionToken, inject } from '@angular/core';
import { DOCUMENT } from '@angular/common';

/**
 * Where the organizer console lives.
 *
 * A third host alongside the site and the API, and the site links to it from
 * every page — the header, the footer, and the band on the front page that
 * exists to recruit organizers. Those are the most commercially important
 * links here, so they cannot be relative: the console serves no route on
 * myfiesta.ca and a relative href would 404 on the site's own router.
 *
 * Resolved at runtime the same way the API base is, and for the same reason:
 * one bundle serves staging and production, so a staging build promoted to
 * production cannot carry a staging console URL baked into it.
 */
export const CONSOLE_URL = new InjectionToken<string>('CONSOLE_URL', {
  providedIn: 'root',
  factory: () => {
    if (typeof window === 'undefined') {
      return process.env['CONSOLE_URL'] ?? 'http://localhost:4310';
    }

    const meta = inject(DOCUMENT).querySelector<HTMLMetaElement>('meta[name="console-url"]');

    return meta?.content ?? '';
  },
});
