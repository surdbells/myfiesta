import { InjectionToken, inject } from '@angular/core';
import { DOCUMENT } from '@angular/common';

/**
 * Where the API lives, resolved once and injected everywhere.
 *
 * The site and the API are separate hosts — myfiesta.ca and api.myfiesta.ca —
 * so a relative URL is wrong in both halves of this app, for different reasons.
 * On the server there is no origin to resolve it against; in the browser it
 * would resolve against the site's own origin, which serves no API at all.
 *
 * The value is therefore absolute in both, and supplied at runtime rather than
 * baked in at build time: one bundle then serves staging and production, which
 * is what keeps a staging build from ever being promoted with the wrong host
 * compiled into it.
 */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL', {
  providedIn: 'root',
  factory: () => {
    // Server: read the environment directly.
    if (typeof window === 'undefined') {
      return process.env['API_BASE_URL'] ?? 'http://127.0.0.1:8000';
    }

    // Browser: read what the server stamped into the document. The server is
    // the only thing that knows which environment this is.
    const meta = inject(DOCUMENT).querySelector<HTMLMetaElement>('meta[name="api-base"]');

    return meta?.content ?? '';
  },
});
