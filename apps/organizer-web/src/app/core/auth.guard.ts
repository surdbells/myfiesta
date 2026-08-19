import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { SessionStore } from './session';

/**
 * Keeps the console's screens behind a session.
 *
 * Convenience, not security. The API authorises every request against the
 * organization owning the record, so a guard that could be bypassed by editing
 * a URL still reaches an API that refuses. This exists so somebody signed out
 * sees a sign-in form rather than a screen of failed requests.
 */
export const requireSession: CanActivateFn = (_route, state) => {
  const session = inject(SessionStore);
  const router = inject(Router);

  if (session.signedIn()) {
    return true;
  }

  // Remembered, so signing in returns them where they were going.
  return router.createUrlTree(['/sign-in'], {
    queryParams: state.url === '/' ? undefined : { next: state.url },
  });
};
