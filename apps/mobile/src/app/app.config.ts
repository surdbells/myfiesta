import {
  ApplicationConfig,
  ErrorHandler,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { RouteReuseStrategy, provideRouter, withComponentInputBinding, withViewTransitions } from '@angular/router';
import { routes } from './app.routes';
import { SessionStore } from './core/session';
import { Reminders } from './core/reminders';
import { Theme } from './core/theme';
import { Navigation } from './core/navigation';
import { LinkedScreenReuse } from './core/deep-links';
import { ReportingErrorHandler } from './core/error-reporting';

/**
 * Whether the phone has been asked for less movement.
 *
 * Read once, at startup: iOS and Android both require the app to be relaunched
 * for the setting to take effect elsewhere, so re-reading it mid-session would
 * be the one place in the app that behaves differently from the rest of it.
 */
function prefersLessMotion(): boolean {
  return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Screens move the way the phone's own apps move them.
 *
 * Forward slides the new screen in from the right over the old one; back
 * slides the top one away to reveal what was underneath; moving between tabs
 * is a quick cross-fade, because tabs are places side by side rather than a
 * stack. The direction comes from Navigation, which knows whether this was a
 * push, a pop or a switch — the one thing the router alone cannot say.
 *
 * Nothing moves when the phone has been asked for less motion: the transitions
 * are left out entirely rather than shortened.
 */
const transitions = withViewTransitions({
  skipInitialTransition: true,
  onViewTransitionCreated: ({ transition }) => {
    const root = document.documentElement;
    const direction = inject(Navigation).direction();

    root.dataset['nav'] = direction;
    void transition.finished.finally(() => delete root.dataset['nav']);
  },
});

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // Angular's handler, then a report to Sentry when the build names one.
    { provide: ErrorHandler, useClass: ReportingErrorHandler },
    prefersLessMotion()
      ? provideRouter(routes, withComponentInputBinding())
      : provideRouter(routes, withComponentInputBinding(), transitions),

    // A link to one event can arrive while another's screen is open.
    { provide: RouteReuseStrategy, useClass: LinkedScreenReuse },

    /*
     * The saved theme and the saved session, before the first route resolves.
     *
     * Both live in native storage, which is asynchronous, and routing that
     * starts first makes two visible mistakes: a signed-in phone shows the
     * sign-in screen for a moment, and a phone set to dark paints one white
     * frame into somebody's face at a dark venue.
     */
    provideAppInitializer(async () => {
      // Listening before the first navigation, so it knows where the app opened.
      inject(Navigation);

      const theme = inject(Theme);
      const session = inject(SessionStore);
      const reminders = inject(Reminders);

      await theme.restore();
      await session.restore();
      await reminders.restore();
    }),
  ],
};
