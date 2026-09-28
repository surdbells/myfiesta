import { InjectionToken, inject } from '@angular/core';
import { DOCUMENT } from '@angular/common';

/** Where the phone app is, in each store. Empty until it is listed there. */
export interface StoreLinks {
  ios: string;
  android: string;
}

/**
 * The App Store and Google Play listings, told to the site at run time.
 *
 * Resolved the way the console's address is (CONSOLE_URL), and for the same
 * reason: one build serves staging and production, and a store link baked
 * into the bundle is one nobody can change without a release. Unset, each is
 * empty and the site hides that button — and the whole promo when both are —
 * rather than showing a badge that leads nowhere.
 *
 * Only https addresses are taken. Anything else is a typo in a deploy, and a
 * link on the front page is the wrong place to find it.
 */
export const STORE_LINKS = new InjectionToken<StoreLinks>('STORE_LINKS', {
  providedIn: 'root',
  factory: () => {
    const safe = (value: string | null | undefined) => {
      const trimmed = (value ?? '').trim();

      return /^https:\/\/[^\s"<>]+$/.test(trimmed) ? trimmed : '';
    };

    if (typeof window === 'undefined') {
      return { ios: safe(process.env['APP_STORE_URL']), android: safe(process.env['PLAY_STORE_URL']) };
    }

    const document = inject(DOCUMENT);
    const read = (name: string) => document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`)?.content;

    return { ios: safe(read('app-store-url')), android: safe(read('play-store-url')) };
  },
});
